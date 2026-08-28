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
        bool $withInertiaComponent = false,
        bool $named = false,
        array $relatedRoutes = [],
        bool $tmpMethod = false,
        private readonly ?LocaleRouteMetadata $metadata = null,
        private readonly ?string $defaultLocale = null,
    ) {
        parent::__construct($route, $withForm, $withInertiaComponent, $named, $relatedRoutes, $tmpMethod);

        if ($this->metadata !== null && ! $this->routeHasLocaleParameter()) {
            $this->hasParameters = true;
        }
    }

    public function controllerMethod(): string
    {
        if ($this->metadata === null || count($this->relatedRoutes) > 1) {
            return parent::controllerMethod();
        }

        return $this->localizedTemplateConstant().PHP_EOL.PHP_EOL.parent::controllerMethod();
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

        $types = [];
        $paramTypeObject = null;

        foreach ($this->route->parameters() as $parameter) {
            $types = $parameter->name === $this->metadata->localeParameter
                ? [$this->metadata->localeUnionType()]
                : array_map(fn ($type) => TypeScript::fromSurveyorType($type), $parameter->types);

            $baseTypes = $types;

            if ($parameter->key) {
                $paramTypeObject = TypeScript::typeObject();
                $paramTypeObject->key($parameter->key)->value(TypeScript::union($baseTypes));
                $baseTypes[] = (string) $paramTypeObject;
            }

            $tuple->item($baseTypes, TypeScript::safeMethod($parameter->name, 'Param'));
            $typeObject->key($parameter->name)->value(TypeScript::union($baseTypes))->optional($parameter->optional);
        }

        if (! $this->routeHasLocaleParameter()) {
            $typeObject
                ->key($this->metadata->localeParameter)
                ->value($this->metadata->localeUnionType())
                ->optional($this->metadata->localeOptional);
        }

        $argTypes = [$typeObject, $tuple];

        if ($this->route->parameters()->count() === 1) {
            array_push($argTypes, ...$types);

            if ($paramTypeObject !== null) {
                $argTypes[] = $paramTypeObject;
            }
        }

        return $this->argTypes = $argTypes;
    }

    protected function url(): string
    {
        $url = parent::url();

        if ($this->metadata === null || ! $this->hasParameters) {
            return $url;
        }

        $url = $this->fillOptionalLocale($url);
        $url = $this->stripUnusedParsedArgsForRouteWithNoRealParameters($url);

        $routeCarriesLocale = $this->routeHasLocaleParameter();

        $localeExpression = $routeCarriesLocale
            ? "{$this->parsedArgsParam}.{$this->metadata->localeParameter}"
            : "{$this->argsParam}?.{$this->metadata->localeParameter}";

        $indexExpression = $this->metadata->localeOptional
            ? sprintf('%s ?? "%s"', $localeExpression, $this->fallbackLocaleForIndexNarrowing())
            : $localeExpression;

        $lookup = sprintf(
            'return (%s[%s] ?? %s.definition.url)',
            $this->localizedTemplatesVariableName(),
            $indexExpression,
            $this->name,
        );

        if (! $routeCarriesLocale) {
            $lookup .= PHP_EOL.TypeScript::indent(sprintf(
                '.replace("{%s?}", (%s ?? "").toString())',
                $this->metadata->localeParameter,
                $localeExpression,
            ));
        }

        return str_replace("return {$this->name}.definition.url", $lookup, $url);
    }

    private function routeHasLocaleParameter(): bool
    {
        if ($this->metadata === null) {
            return false;
        }

        return $this->route->parameters()->contains(
            fn ($parameter) => $parameter->name === $this->metadata->localeParameter,
        );
    }

    private function fallbackLocaleForIndexNarrowing(): string
    {
        return $this->defaultLocale ?? $this->metadata->locales[0];
    }

    private function stripUnusedParsedArgsForRouteWithNoRealParameters(string $url): string
    {
        if ($this->routeHasLocaleParameter() || $this->route->parameters()->isNotEmpty()) {
            return $url;
        }

        return (string) preg_replace(
            '/^[ \t]*const '.preg_quote($this->parsedArgsParam, '/').' = \{\}[ \t]*\n/m',
            '',
            $url,
            1,
        );
    }

    private function fillOptionalLocale(string $url): string
    {
        if (! $this->metadata->localeOptional || $this->defaultLocale === null) {
            return $url;
        }

        $anchor = "{$this->argsParam} = applyUrlDefaults({$this->argsParam})";

        $fill = sprintf(
            'if (%s?.%s === undefined) { %s = { ...(%s ?? {}), %s: "%s" } }',
            $this->argsParam,
            $this->metadata->localeParameter,
            $this->argsParam,
            $this->argsParam,
            $this->metadata->localeParameter,
            $this->defaultLocale,
        );

        return (string) preg_replace_callback(
            '/^([ \t]*)'.preg_quote($anchor, '/').'$/m',
            fn (array $matches): string => $matches[0].PHP_EOL.PHP_EOL.$matches[1].$fill,
            $url,
            1,
        );
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
