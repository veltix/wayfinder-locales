<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales\Wayfinder;

use Laravel\Ranger\Components\Route;
use Laravel\Wayfinder\Langs\TypeScript;
use Laravel\Wayfinder\Langs\TypeScript\Converters\RouteMethod;
use Veltix\WayfinderLocales\Route\LocaleRouteMetadata;

/**
 * Wayfinder's route method, plus a per-locale URL template table.
 *
 * Laravel serves one `{locale}`-parameterised URI, so Wayfinder would emit one
 * `definition.url`. This picks the template matching the locale argument at
 * call time, which is what makes `/de/produkte` come out of `products('de')`.
 *
 * Everything else is inherited and post-processed rather than copied: the base
 * URL builder is ~140 lines of Wayfinder internals that move between releases,
 * and a stale copy of them fails silently.
 */
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
    }

    public function controllerMethod(): string
    {
        if ($this->metadata === null || count($this->relatedRoutes) > 1) {
            return parent::controllerMethod();
        }

        return $this->localizedTemplateConstant().PHP_EOL.PHP_EOL.parent::controllerMethod();
    }

    /**
     * Narrow the locale argument from whatever was inferred for the route
     * parameter to the locales the route actually declares.
     *
     * This one is a copy of the parent rather than a post-process: the argument
     * types are builder objects, and there is no way to re-open one after the
     * fact. LocalizedRouteEmitTest fails loudly if the parent's version moves
     * out from under it.
     */
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

        // The parent's `.replace()` chain and `queryParams()` suffix hang off
        // this expression, so substituting it in place keeps all of them.
        return str_replace(
            "return {$this->name}.definition.url",
            sprintf(
                'return (%s[%s.%s] ?? %s.definition.url)',
                $this->localizedTemplatesVariableName(),
                $this->parsedArgsParam,
                $this->metadata->localeParameter,
                $this->name,
            ),
            $url,
        );
    }

    /**
     * An optional `{locale?}` may be omitted at the call site, which would
     * index the template table with `undefined`. Default it first.
     */
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
