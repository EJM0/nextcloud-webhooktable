<?php

declare(strict_types=1);

namespace OCA\WebhookTable\Controller;

use OCA\WebhookTable\AppInfo\Application;
use OCA\WebhookTable\Exception\WebhookException;
use OCA\WebhookTable\Service\EndpointService;
use OCA\WebhookTable\Service\SubmissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Verwaltung der Webhooks. Ohne #[NoAdminRequired] sind alle Methoden
 * ausschließlich für Administratoren erreichbar.
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class SettingsController extends Controller {
	public function __construct(
		IRequest $request,
		private EndpointService $endpoints,
		private SubmissionService $submissions,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[FrontpageRoute(verb: 'GET', url: '/settings/endpoints')]
	public function index(): JSONResponse {
		return new JSONResponse($this->endpoints->findAll());
	}

	#[FrontpageRoute(verb: 'POST', url: '/settings/endpoints')]
	public function create(): JSONResponse {
		return $this->handle(fn () => $this->endpoints->create(
			$this->input(),
			$this->userSession->getUser()?->getUID() ?? '',
		));
	}

	#[FrontpageRoute(verb: 'PUT', url: '/settings/endpoints/{id}')]
	public function update(string $id): JSONResponse {
		return $this->handle(fn () => $this->endpoints->update($id, $this->input()));
	}

	#[FrontpageRoute(verb: 'DELETE', url: '/settings/endpoints/{id}')]
	public function destroy(string $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$this->endpoints->delete($id);
			return ['status' => 'ok'];
		});
	}

	#[FrontpageRoute(verb: 'POST', url: '/settings/endpoints/{id}/token')]
	public function regenerateToken(string $id): JSONResponse {
		return $this->handle(fn () => $this->endpoints->regenerateToken($id));
	}

	/**
	 * Schreibt eine Testzeile in die Zieldatei, damit Speicherort und Rechte geprüft werden.
	 */
	#[FrontpageRoute(verb: 'POST', url: '/settings/endpoints/{id}/test')]
	public function test(string $id): JSONResponse {
		return $this->handle(function () use ($id) {
			$endpoint = $this->endpoints->find($id);
			if ($endpoint === null) {
				throw new WebhookException('Webhook nicht gefunden.');
			}
			$endpoint['onlyMapped'] = false;
			$user = $this->userSession->getUser()?->getDisplayName() ?? '';
			try {
				$this->submissions->store($endpoint, ['Testeintrag' => 'Test aus den Einstellungen (' . $user . ')']);
			} catch (WebhookException $e) {
				$this->endpoints->recordError($id, $e->getMessage());
				throw $e;
			}
			return $this->endpoints->find($id);
		});
	}

	/**
	 * @return array<string, mixed>
	 */
	private function input(): array {
		$keys = ['name', 'enabled', 'owner', 'folder', 'filename', 'format', 'timestamp', 'mapping', 'onlyMapped'];
		$input = [];
		foreach ($keys as $key) {
			$value = $this->request->getParam($key);
			if ($value !== null) {
				$input[$key] = $value;
			}
		}
		foreach (['enabled', 'timestamp', 'onlyMapped'] as $bool) {
			if (isset($input[$bool])) {
				$input[$bool] = filter_var($input[$bool], FILTER_VALIDATE_BOOLEAN);
			}
		}
		return $input;
	}

	private function handle(callable $fn): JSONResponse {
		try {
			return new JSONResponse($fn());
		} catch (WebhookException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('Webhook-Tabelle: Fehler in den Einstellungen', ['exception' => $e]);
			return new JSONResponse(['message' => 'Interner Fehler: ' . $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
