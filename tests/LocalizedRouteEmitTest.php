<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales\Tests;

use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Collection;
use Laravel\Ranger\Components\Route as RangerRoute;
use Laravel\Wayfinder\Langs\TypeScript\Converters\RouteMethod;
use Laravel\Wayfinder\Registry\ResultConverter;
use Laravel\Wayfinder\Registry\TypeScriptConverter;
use PHPUnit\Framework\Attributes\Test;
use Veltix\WayfinderLocales\Route\LocaleRouteResolver;
use Veltix\WayfinderLocales\Wayfinder\LocalizedRouteMethod;
use Veltix\WayfinderLocales\Wayfinder\TypeScriptEmitterExtension;

class LocalizedRouteEmitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! ResultConverter::getRegistry()->hasConverter(TypeScriptConverter::class)) {
            ResultConverter::register(TypeScriptConverter::class);
        }
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('wayfinder-locales.locales', ['en', 'de']);
        $app['config']->set('wayfinder-locales.default_locale', 'en');
        $app['config']->set('wayfinder-locales.hide_default_prefix', true);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/{locale}/products/{product}', fn () => 'ok')
            ->name('products')
            ->localized(['en' => 'products', 'de' => 'produkte']);

        $router->get('/{locale?}/about', fn () => 'ok')
            ->name('about')
            ->localized(['en' => 'about', 'de' => 'ueber-uns']);

        $router->get('/status', fn () => 'ok')->name('status');
    }

    #[Test]
    public function it_emits_a_per_locale_url_template_table(): void
    {
        $this->assertStringContainsString(
            'const productsLocalizedTemplates = { en: "/products/{product}", de: "/{locale}/produkte/{product}" } as const',
            $this->emit('products'),
        );
    }

    #[Test]
    public function it_selects_the_template_for_the_locale_argument(): void
    {
        $this->assertStringContainsString(
            'return (productsLocalizedTemplates[parsedArgs.locale] ?? products.definition.url)',
            $this->emit('products'),
        );
    }

    #[Test]
    public function it_keeps_wayfinders_placeholder_replacement_and_query_params(): void
    {
        $emitted = $this->emit('products');

        $this->assertStringContainsString('.replace("{product}", parsedArgs.product.toString())', $emitted);
        $this->assertStringContainsString('+ queryParams(options)', $emitted);
    }

    #[Test]
    public function it_narrows_the_locale_argument_to_the_declared_locales(): void
    {
        $emitted = $this->emit('products');

        $this->assertStringContainsString('locale: "en" | "de"', $emitted);
        $this->assertStringContainsString('product: string | number', $emitted);
    }

    #[Test]
    public function it_defaults_an_optional_locale_before_the_template_lookup(): void
    {
        $this->assertStringContainsString(
            'if (args?.locale === undefined) { args = { ...(args ?? {}), locale: "en" } }',
            $this->emit('about'),
        );
    }

    #[Test]
    public function it_leaves_untagged_routes_to_wayfinders_own_method(): void
    {
        $method = $this->extension()->makeRouteMethod($this->rangerRoute('status'), withForm: false, named: true);

        $this->assertNotInstanceOf(LocalizedRouteMethod::class, $method);
        $this->assertInstanceOf(RouteMethod::class, $method);
        $this->assertStringNotContainsString('LocalizedTemplates', $method->controllerMethod());
    }

    #[Test]
    public function it_uses_the_localized_method_for_tagged_routes(): void
    {
        $this->assertInstanceOf(
            LocalizedRouteMethod::class,
            $this->extension()->makeRouteMethod($this->rangerRoute('products'), withForm: false, named: true),
        );
    }

    private function emit(string $name): string
    {
        return $this->extension()
            ->makeRouteMethod($this->rangerRoute($name), withForm: false, named: true)
            ->controllerMethod();
    }

    private function extension(): TypeScriptEmitterExtension
    {
        $extension = $this->app->make(TypeScriptEmitterExtension::class);
        $resolver = $this->app->make(LocaleRouteResolver::class);

        foreach (['products', 'about', 'status'] as $name) {
            $route = $this->rangerRoute($name);
            $metadata = $resolver->resolveForRangerRoute($route);

            if ($metadata !== null) {
                $extension->register($route, $metadata);
            }
        }

        return $extension;
    }

    private function rangerRoute(string $name): RangerRoute
    {
        /** @var IlluminateRoute $route */
        $route = $this->app['router']->getRoutes()->getByName($name);

        return new RangerRoute($route, new Collection, null, null);
    }
}
