<?php

declare(strict_types=1);

namespace SimpleSAML\Module\saml\Controller;

use Exception;
use Nyholm\Psr7\Factory\Psr17Factory;
use SimpleSAML\Assert\Assert;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\IdP;
use SimpleSAML\Logger;
use SimpleSAML\Metadata;
use SimpleSAML\Module;
use SimpleSAML\SAML2\Exception\Protocol\UnsupportedBindingException;
use SimpleSAML\SAML2\ArtifactResolve;
use SimpleSAML\SAML2\ArtifactResponse;
use SimpleSAML\SAML2\SOAP;
use SimpleSAML\SAML2\XML\saml\Issuer;
use SimpleSAML\Store\StoreFactory;
use SimpleSAML\XML\DOMDocumentFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\{Request, Response};

/**
 * Controller class for the Web Browser Single Sign On profile.
 *
 * This class serves the different views available.
 *
 * @package simplesamlphp/simplesamlphp
 */
class WebBrowserSingleSignOn
{
    /** @var \SimpleSAML\Configuration */
    protected Configuration $config;


    /**
     * Controller constructor.
     *
     * It initializes the global configuration for the controllers implemented here.
     *
     * @param \SimpleSAML\Configuration $config The configuration to use by the controllers.
     */
    public function __construct(
        Configuration $config
    ) {
        $this->config = $config;
    }


    /**
     * The ArtifactResolutionService receives the samlart from the sp.
     * And when the artifact is found, it sends a \SimpleSAML\SAML2\ArtifactResponse.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    public function artifactResolutionService(Request $request): Response
    {
        if ($this->config->getBoolean('enable.saml20-idp') === false || !Module::isModuleEnabled('saml')) {
            throw new Error\Error('NOACCESS', null, 403);
        }

        $metadata = Metadata\MetaDataStorageHandler::getMetadataHandler($this->config);
        $idpEntityId = $metadata->getMetaDataCurrentEntityID('saml20-idp-hosted');
        $idpMetadata = $metadata->getMetaDataConfig($idpEntityId, 'saml20-idp-hosted');

        if (!$idpMetadata->getOptionalBoolean('saml20.sendartifact', false)) {
            throw new Error\Error('NOACCESS');
        }

        $storeType = $this->config->getOptionalString('store.type', 'phpsession');
        $store = StoreFactory::getInstance($storeType);
        if ($store === false) {
            throw new Exception('Unable to send artifact without a datastore configured.');
        }

        $psr17Factory = new Psr17Factory();
        $psrHttpFactory = new PsrHttpFactory($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
        $psrRequest = $psrHttpFactory->createRequest($request);

        $binding = new SOAP();
        try {
            $request = $binding->receive($psrRequest);
        } catch (UnsupportedBindingException $e) {
            throw new Error\Error('ARSPARAMS', $e, 400);
        }

        if (!($request instanceof ArtifactResolve)) {
            throw new Exception("Message received on ArtifactResolutionService wasn't a ArtifactResolve request.");
        }

        $issuer = $request->getIssuer();
        /** @psalm-assert \SimpleSAML\SAML2\XML\saml\Issuer $issuer */
        Assert::notNull($issuer);
        $issuer = $issuer->getValue();
        $spMetadata = $metadata->getMetaDataConfig($issuer, 'saml20-sp-remote');
        $artifact = $request->getArtifact();
        $responseData = $store->get('artifact', $artifact);
        $store->delete('artifact', $artifact);

        if ($responseData !== null) {
            $document = DOMDocumentFactory::fromString($responseData);
            $responseXML = $document->documentElement;
        } else {
            $responseXML = null;
        }

        $artifactResponse = new ArtifactResponse();
        $issuer = new Issuer();
        $issuer->setValue($idpEntityId);
        $artifactResponse->setIssuer($issuer);

        $artifactResponse->setInResponseTo($request->getId());
        $artifactResponse->setAny($responseXML);
        Module\saml\Message::addSign($idpMetadata, $spMetadata, $artifactResponse);
        $psrResponse = $binding->send($artifactResponse);
        $httpFoundationFactory = new HttpFoundationFactory();
        return $httpFoundationFactory->createResponse($psrResponse);
    }


    /**
     * The SSOService is part of the SAML 2.0 IdP code, and it receives incoming Authentication Requests
     * from a SAML 2.0 SP, parses, and process it, and then authenticates the user and sends the user back
     * to the SP with an Authentication Response.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    public function singleSignOnService(Request $request): Response
    {
        Logger::info('SAML2.0 - IdP.SSOService: Accessing SAML 2.0 IdP endpoint SSOService');

        if ($this->config->getBoolean('enable.saml20-idp') === false || !Module::isModuleEnabled('saml')) {
            throw new Error\Error('NOACCESS', null, 403);
        }

        $metadata = Metadata\MetaDataStorageHandler::getMetadataHandler($this->config);
        $idpEntityId = $metadata->getMetaDataCurrentEntityID('saml20-idp-hosted');
        $idp = IdP::getById($this->config, 'saml2:' . $idpEntityId);

        try {
            return Module\saml\IdP\SAML2::receiveAuthnRequest($request, $idp);
        } catch (UnsupportedBindingException $e) {
            throw new Error\Error('SSOPARAMS', $e, 400);
        }
    }
}
