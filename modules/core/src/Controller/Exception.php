<?php

declare(strict_types=1);

namespace SimpleSAML\Module\core\Controller;

use SimpleSAML\{Auth, Configuration, Error, Logger, Module, Session, Utils};
use SimpleSAML\XHTML\Template;
use Symfony\Component\HttpFoundation\{RedirectResponse, Request, Response};

use function array_keys;
use function implode;
use function urlencode;

/**
 * Controller class for the core module.
 *
 * This class serves the different views available in the module.
 *
 * @package SimpleSAML\Module\core
 */
class Exception
{
    /** @var \SimpleSAML\Configuration */
    protected Configuration $config;

    /** @var \SimpleSAML\Session */
    protected Session $session;


    /**
     * Controller constructor.
     *
     * It initializes the global configuration and auth source configuration for the controllers implemented here.
     *
     * @param \SimpleSAML\Configuration              $config The configuration to use by the controllers.
     * @param \SimpleSAML\Session                    $session The session to use by the controllers.
     *
     * @throws \Exception
     */
    public function __construct(
        Configuration $config,
        Session $session
    ) {
        $this->config = $config;
        $this->session = $session;
    }


    /**
     * Show cardinality error.
     *
     * @param Request $request The request that lead to this login operation.
     * @throws \SimpleSAML\Error\BadRequest
     * @return \SimpleSAML\XHTML\Template  An HTML template
     */
    public function cardinality(Request $request): Response
    {
        $stateId = $request->query->get('StateId', false);
        if ($stateId === false) {
            throw new Error\BadRequest('Missing required StateId query parameter.');
        }

        $state = Auth\State::loadState($stateId, 'core:cardinality');
        Logger::stats(
            'core:cardinality:error ' . $state['Destination']['entityid'] . ' ' . $state['saml:sp:IdP'] .
            ' ' . implode(',', array_keys($state['core:cardinality:errorAttributes']))
        );

        $t = new Template($this->config, 'core:cardinality_error.twig');
        $t->data['cardinalityErrorAttributes'] = $state['core:cardinality:errorAttributes'];
        if (isset($state['Source']['auth'])) {
            $t->data['LogoutURL'] = Module::getModuleURL(
                'saml/sp/login/' . urlencode($state['Source']['auth'])
            );
        }

        $t->setStatusCode(403);
        return $t;
    }


    /**
     * Show missing cookie error.
     *
     * @param Request $request The request that lead to this login operation.
     * @return \SimpleSAML\XHTML\Template|\Symfony\Component\HttpFoundation\RedirectResponse
     *   An HTML template or a redirection if we are not authenticated.
     */
    public function nocookie(Request $request): Template|RedirectResponse
    {
        $retryURL = $request->query->get('retryURL', null);
        if ($retryURL !== null) {
            $httpUtils = new Utils\HTTP();
            $retryURL = $httpUtils->checkURLAllowed(strval($retryURL));
        }

        $t = new Template($this->config, 'core:no_cookie.twig');
        $t->data['retryURL'] = $retryURL;

        return $t;
    }


    /**
     * Show a warning to an user about the SP requesting SSO a short time after
     * doing it previously.
     *
     * @param Request $request The request that lead to this login operation.
     *
     * @return \SimpleSAML\XHTML\Template|\Symfony\Component\HttpFoundation\Response
     * An HTML template, a redirect or a "runnable" response.
     *
     * @throws \SimpleSAML\Error\BadRequest
     */
    public function shortSsoInterval(Request $request): Template|Response
    {
        $stateId = $request->query->get('StateId', false);
        if ($stateId === false) {
            throw new Error\BadRequest('Missing required StateId query parameter.');
        }

        $state = Auth\State::loadState($stateId, 'core:short_sso_interval');

        $continue = $request->query->get('continue', false);
        if ($continue !== false) {
            // The user has pressed the continue/retry-button
            return Auth\ProcessingChain::resumeProcessing($state);
        }

        $t = new Template($this->config, 'core:short_sso_interval.twig');
        $t->data['params'] = ['StateId' => $stateId];
        $t->data['trackId'] = $this->session->getTrackID();
        $t->data['autofocus'] = 'contbutton';

        return $t;
    }
}
