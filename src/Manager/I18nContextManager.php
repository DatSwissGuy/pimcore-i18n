<?php

/*
 * This source file is available under two different licenses:
 *   - GNU General Public License version 3 (GPLv3)
 *   - DACHCOM Commercial License (DCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) DACHCOM.DIGITAL AG (https://www.dachcom-digital.com)
 * @license    GPLv3 and DCL
 */

namespace I18nBundle\Manager;

use I18nBundle\Adapter\PathGenerator\PathGeneratorInterface;
use I18nBundle\Builder\RouteItemBuilder;
use I18nBundle\Builder\ZoneBuilder;
use I18nBundle\Builder\ZoneSitesBuilder;
use I18nBundle\Context\I18nContext;
use I18nBundle\Context\I18nContextInterface;
use I18nBundle\Definitions;
use I18nBundle\Exception\RouteItemException;
use I18nBundle\Exception\ZoneSiteNotFoundException;
use I18nBundle\Model\LocaleDefinition;
use I18nBundle\Model\LocaleDefinitionInterface;
use I18nBundle\Model\RouteItem\RouteItemInterface;
use I18nBundle\Model\Zone;
use I18nBundle\Model\ZoneInterface;
use I18nBundle\Registry\LocaleProviderRegistry;
use I18nBundle\Registry\PathGeneratorRegistry;
use Pimcore\Model\Document;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\OptionsResolver\OptionsResolver;

class I18nContextManager
{
    public function __construct(
        protected ZoneBuilder $zoneBuilder,
        protected ZoneSitesBuilder $zoneSitesBuilder,
        protected RouteItemBuilder $routeItemBuilder,
        protected LocaleProviderRegistry $localeProviderRegistry,
        protected PathGeneratorRegistry $pathGeneratorRegistry,
    ) {
    }

    /**
     * @throws ZoneSiteNotFoundException
     * @throws RouteItemException
     */
    public function buildContextByParameters(string $type, array $i18nRouteParameters, bool $fullBootstrap = false): ?I18nContextInterface
    {
        $routeItem = $this->routeItemBuilder->buildRouteItemByParameters($type, $i18nRouteParameters);

        $zone = $this->setupZone($routeItem, $fullBootstrap);
        $pathGenerator = $this->setupPathGenerator($routeItem, $fullBootstrap);
        $localeDefinition = $this->buildLocaleDefinition($routeItem);

        return new I18nContext($routeItem, $zone, $localeDefinition, $pathGenerator);
    }

    /**
     * @throws ZoneSiteNotFoundException
     * @throws RouteItemException
     */
    public function buildContextByRequest(Request $baseRequest, ?Document $baseDocument, bool $fullBootstrap = false): ?I18nContextInterface
    {
        $routeItem = $this->routeItemBuilder->buildRouteItemByRequest($baseRequest, $baseDocument);

        if (!$routeItem instanceof RouteItemInterface) {
            return null;
        }

        $zone = $this->setupZone($routeItem, $fullBootstrap);
        $pathGenerator = $this->setupPathGenerator($routeItem, $fullBootstrap);
        $localeDefinition = $this->buildLocaleDefinition($routeItem);

        return new I18nContext($routeItem, $zone, $localeDefinition, $pathGenerator);
    }

    protected function setupPathGenerator(RouteItemInterface $routeItem, bool $fullBootstrap = false): ?PathGeneratorInterface
    {
        if ($fullBootstrap === false) {
            return null;
        }

        $pathGeneratorOptionsResolver = new OptionsResolver();
        $pathGeneratorOptionsResolver->setDefined(array_keys($routeItem->getRouteAttributes()));
        $pathGeneratorOptionsResolver->resolve($routeItem->getRouteAttributes());

        $pathGenerator = $this->buildPathGenerator($routeItem->getType());
        $pathGenerator->configureOptions($pathGeneratorOptionsResolver);

        return $pathGenerator;
    }

    protected function setupZone(RouteItemInterface $routeItem, bool $fullBootstrap = false): ZoneInterface
    {
        $zone = $this->zoneBuilder->buildZone($routeItem);

        // we don't want to add those two methods to the interface
        // since they are kind of internal!
        if ($zone instanceof Zone) {
            $zone->processProviderLocales($this->localeProviderRegistry->get($zone->getLocaleAdapterName()));
            $zone->setSites($this->zoneSitesBuilder->buildZoneSites($zone, $routeItem, $fullBootstrap));
        }

        return $zone;
    }

    protected function buildLocaleDefinition(RouteItemInterface $routeItem): LocaleDefinitionInterface
    {
        $baseLocale = $routeItem->getLocaleFragment();

        $locale = $baseLocale === '' ? null : $baseLocale;
        $languageIso = $locale;
        $countryIso = Definitions::INTERNATIONAL_COUNTRY_NAMESPACE;

        if ($baseLocale !== null && str_contains($baseLocale, '_')) {
            $parts = explode('_', $baseLocale);
            $languageIso = strtolower($parts[0]);
            if (!empty($parts[1])) {
                $countryIso = strtoupper($parts[1]);
            }
        }

        return new LocaleDefinition(
            $locale,
            $languageIso,
            $countryIso
        );
    }

    public function buildPathGenerator(?string $pathGeneratorIdentifier): PathGeneratorInterface
    {
        if (!$this->pathGeneratorRegistry->has($pathGeneratorIdentifier)) {
            throw new \Exception(
                sprintf(
                    'path.generator adapter "%s" is not available. please use "%s" tag to register new adapter and add "%s" as a alias.',
                    $pathGeneratorIdentifier,
                    'i18n.adapter.path.generator',
                    $pathGeneratorIdentifier
                )
            );
        }

        return $this->pathGeneratorRegistry->get($pathGeneratorIdentifier);
    }
}
