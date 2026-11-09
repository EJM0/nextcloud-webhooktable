<?php

declare(strict_types=1);

namespace OCA\WebhookTable\Settings;

use OCA\WebhookTable\AppInfo\Application;
use OCA\WebhookTable\Service\EndpointService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Settings\ISettings;

class Admin implements ISettings {
	public function __construct(
		private EndpointService $endpoints,
		private IInitialState $initialState,
		private IURLGenerator $urlGenerator,
		private IUserSession $userSession,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('endpoints', $this->endpoints->findAll());
		$this->initialState->provideInitialState('currentUser', $this->userSession->getUser()?->getUID() ?? '');
		// Basis-URL des Webhooks, „__ID__“ wird in der Oberfläche ersetzt
		$this->initialState->provideInitialState(
			'hookUrl',
			$this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.webhook.receive', ['id' => '__ID__']),
		);
		return new TemplateResponse(Application::APP_ID, 'admin');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
