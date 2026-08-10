<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales\Wayfinder;

use Laravel\Ranger\Components\Route;
use Laravel\Wayfinder\Langs\TypeScript;
use Laravel\Wayfinder\Langs\TypeScript\Converters\RouteMethod;
use Veltix\WayfinderLocales\Route\LocaleRouteMetadata;

final class LocalizedRouteMethod extends RouteMethod
{
    public function __construct(
        Route $route,
        bool $withForm,
        bool $named = false,
        array $relatedRoutes = [],
        bool $tmpMethod = false,
        private readonly ?LocaleRouteMetadata $metadata = null,
        private readonly ?string $defaultLocale = null,
    ) {
        parent::__construct($route, $withForm, $named, $relatedRoutes, $tmpMethod);
    }

    public function controllerMethod(): string
    {
        if ($this->metadata === null || count($this->relatedRoutes) > 1) {
            return parent::controllerMethod();
        }

        return implode(PHP_EOL.PHP_EOL, array_filter([
            trim($this->localizedTemplateConstant()),
            trim($this->base()),
            trim($this->definition()),
            trim($this->url()),
            ...array_map('trim', $this->verbs()),
            trim($this->formVariant()),
            ...array_map('trim', $this->formVerbVariants()),
            $this->withForm ? "{$this->name}.form = {$this->name}Form" : '',
        ]));
    }

    protected function collectArgTypes(): array
    {
        if ($this->metadata === null) {
            return parent::collectArgTypes();
        }

        if (isset($this->argTypes)) {
            return $this->argTypes;
        }

        $typeObject = TypeScript::typeObject();
        $tuple = TypeScript::tuple();

        $singleParamTypes = [];
        $singleParamTypeObject = null;

        foreach ($this->route->parameters() as $parameter) {
            $types = array_map(fn ($type) => TypeScript::fromSurveyorType($type), $parameter->types);

            if ($parameter->name === $this->metadata->localeParameter) {
                $types = [$this->metadata->localeUnionType()];
            }

            $baseTypes = $types;

            if ($parameter->key) {
                $paramTypeObject = TypeScript::typeObject();
                $paramTypeObject->key($parameter->key)->value(TypeScript::union($baseTypes));
                $baseTypes[] = (string) $paramTypeObject;

                $singleParamTypeObject = $paramTypeObject;
            }

            $tuple->item($baseTypes, TypeScript::safeMethod($parameter->name, 'Param'));
            $typeObject->key($parameter->name)->value(TypeScript::union($baseTypes))->optional($parameter->optional);

            $singleParamTypes = $types;
        }

        $argTypes = [$typeObject, $tuple];

        if ($this->route->parameters()->count() === 1) {
            array_push($argTypes, ...$singleParamTypes);

            if ($singleParamTypeObject !== null) {
                $argTypes[] = $singleParamTypeObject;
            }
        }

        return $this->argTypes = $argTypes;
    }

    protected function url(): string
    {
        if ($this->metadata === null) {
            return parent::url();
        }

        $func = TypeScript::arrowFunction();

        if ($this->hasParameters) {
            $func->argument('args', $this->collectArgTypes(), $this->allOptional);
        }

        $func->argument('options', 'RouteQueryOptions', true);

        $body = [];

        if ($this->hasParameters) {
            if ($this->route->parameters()->count() === 1) {
                $body[] = <<<TS
                if (typeof args === "string" || typeof args === "number") {
                    args = { {$this->route->parameters()->first()->name}: args }
                }
                TS;

                if ($this->route->parameters()->first()->key) {
                    $body[] = <<<TS
                    if (typeof args === "object" && !Array.isArray(args) && "{$this->route->parameters()->first()->key}" in args) {
                        args = { {$this->route->parameters()->first()->name}: args.{$this->route->parameters()->first()->key} }
                    }
                    TS;
                }
            }

            $argsArrayObject = TypeScript::object();

            foreach ($this->route->parameters() as $i => $parameter) {
                $argsArrayObject->key($parameter->name)->value("args[{$i}]");
            }

            $body[] = <<<TS
            if (Array.isArray(args)) {
                args = {$argsArrayObject}
            }
            TS;

            $body[] = 'args = applyUrlDefaults(args)';

            if ($this->metadata->localeOptional && $this->defaultLocale !== null) {
                $body[] = sprintf(
                    'if (args?.%s === undefined) { args = { ...(args ?? {}), %s: "%s" } }',
                    $this->metadata->localeParameter,
                    $this->metadata->localeParameter,
                    $this->defaultLocale,
                );
            }

            if ($this->route->parameters()->where('optional')->isNotEmpty()) {
                $optionalParams = $this->route
                    ->parameters()
                    ->where('optional')
                    ->pluck('name')
                    ->toJson();

                $body[] = "validateParameters(args, {$optionalParams})";
            }

            $parsedArgsObject = TypeScript::object();

            foreach ($this->route->parameters() as $parameter) {
                $keyVal = $parsedArgsObject->key($parameter->name);

                if ($parameter->key) {
                    $val = sprintf(
                        'typeof args%s.%s === "object" ? args.%s.%s : args%s.%s',
                        $this->allOptional ? '?' : '',
                        $parameter->name,
                        $parameter->name,
                        $parameter->key ?? 'id',
                        $this->allOptional ? '?' : '',
                        $parameter->name,
                    );
                } else {
                    $val = sprintf('args%s.%s', $this->allOptional ? '?' : '', $parameter->name);
                }

                if ($parameter->default !== null) {
                    $val = sprintf('(%s) ?? "%s"', $val, $parameter->default);
                }

                $keyVal->value($val);
            }

            $body[] = (string) TypeScript::constant('parsedArgs', (string) $parsedArgsObject);
        }

        if ($this->hasParameters) {
            $templateLocale = sprintf('parsedArgs.%s', $this->metadata->localeParameter);
            $body[] = sprintf(
                'const localizedTemplate = %s[%s] ?? %s.definition.url',
                $this->localizedTemplatesVariableName(),
                $templateLocale,
                $this->name,
            );
        }

        $return = $this->hasParameters
            ? 'return localizedTemplate'
            : "return {$this->name}.definition.url";

        if ($this->hasParameters) {
            $urlReplace = [];

            foreach ($this->route->parameters() as $parameter) {
                $urlReplace[] = sprintf(
                    '.replace("%s", parsedArgs.%s%s.toString()%s)',
                    $parameter->placeholder,
                    $parameter->name,
                    $parameter->optional ? '?' : '',
                    $parameter->optional ? ' ?? ""' : '',
                );
            }

            $urlReplace[] = '.replace(/\/+$/, "")';

            $urlReplace = implode(
                PHP_EOL,
                array_map(fn ($line) => TypeScript::indent($line), $urlReplace),
            );

            $return .= PHP_EOL.$urlReplace;
        }

        $return .= ' + queryParams(options)';

        $body[] = $return;

        $func->body($body);

        $block = TypeScript::block("{$this->name}.url = {$func}");

        $this->addDockblock($block);

        return (string) $block;
    }

    private function localizedTemplateConstant(): string
    {
        $entries = [];

        foreach ($this->metadata?->localizedUris ?? [] as $locale => $uri) {
            $entries[] = sprintf('%s: "%s"', $locale, $uri);
        }

        return sprintf(
            'const %s = { %s } as const',
            $this->localizedTemplatesVariableName(),
            implode(', ', $entries),
        );
    }

    private function localizedTemplatesVariableName(): string
    {
        return $this->name.'LocalizedTemplates';
    }
}
