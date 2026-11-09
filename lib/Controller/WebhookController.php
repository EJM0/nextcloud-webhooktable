<?php

declare(strict_types=1);

namespace OCA\WebhookTable\Controller;

use OCA\WebhookTable\AppInfo\Application;
use OCA\WebhookTable\Exception\WebhookException;
use OCA\WebhookTable\Service\EndpointService;
use OCA\WebhookTable\Service\SubmissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Öffentlicher Webhook-Endpunkt. Geschützt durch einen geheimen Schlüssel pro Webhook,
 * Bruteforce-Schutz und Ratenbegrenzung.
 *
 * Schlüssel übergeben per
 *   - Header  „X-Webhook-Token: <schlüssel>“
 *   - Header  „Authorization: Bearer <schlüssel>“
 *   - URL     „…/hook/<id>?token=<schlüssel>“ (für Formulare, die keine Header setzen können)
 *
 * @psalm-suppress UnusedClass
 */
class WebhookController extends Controller {
	private const MAX_PAYLOAD_BYTES = 1024 * 1024;
	private const RESERVED_PARAMS = ['id', 'token', '_route'];

	public function __construct(
		IRequest $request,
		private EndpointService $endpoints,
		private SubmissionService $submissions,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'webhooktable_token')]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'POST', url: '/hook/{id}')]
	public function receive(string $id): JSONResponse {
		$endpoint = $this->authenticate($id);
		if ($endpoint instanceof JSONResponse) {
			return $endpoint;
		}

		$payload = array_diff_key($this->request->getParams(), array_flip(self::RESERVED_PARAMS));
		if (strlen((string)json_encode($payload)) > self::MAX_PAYLOAD_BYTES) {
			return new JSONResponse(['status' => 'error', 'message' => 'Anfrage zu groß'], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
		}

		try {
			$columns = $this->submissions->store($endpoint, $payload);
			$this->endpoints->recordSuccess($id);
			return new JSONResponse(['status' => 'ok', 'columns' => $columns]);
		} catch (WebhookException $e) {
			$this->endpoints->recordError($id, $e->getMessage());
			return new JSONResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (\Throwable $e) {
			$this->logger->error('Webhook-Tabelle: Einsendung konnte nicht gespeichert werden', ['exception' => $e, 'endpoint' => $id]);
			$this->endpoints->recordError($id, 'Interner Fehler: ' . $e->getMessage());
			return new JSONResponse(['status' => 'error', 'message' => 'Interner Fehler'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Verbindungstest: prüft nur den Schlüssel, schreibt nichts.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'webhooktable_token')]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'GET', url: '/hook/{id}')]
	public function ping(string $id): JSONResponse {
		$endpoint = $this->authenticate($id);
		if ($endpoint instanceof JSONResponse) {
			return $endpoint;
		}
		return new JSONResponse(['status' => 'ready', 'name' => $endpoint['name']]);
	}

	/**
	 * @return array<string, mixed>|JSONResponse
	 */
	private function authenticate(string $id): array|JSONResponse {
		$token = $this->extractToken();
		$endpoint = $this->endpoints->find($id);

		if ($endpoint === null || $token === '' || !hash_equals((string)$endpoint['token'], $token)) {
			// Gleiche Antwort für unbekannte ID und falschen Schlüssel
			$response = new JSONResponse(['status' => 'error', 'message' => 'Nicht autorisiert'], Http::STATUS_UNAUTHORIZED);
			$response->throttle(['endpoint' => $id]);
			return $response;
		}
		if (!$endpoint['enabled']) {
			return new JSONResponse(['status' => 'error', 'message' => 'Webhook ist deaktiviert'], Http::STATUS_FORBIDDEN);
		}
		return $endpoint;
	}

	private function extractToken(): string {
		$header = trim($this->request->getHeader('X-Webhook-Token'));
		if ($header !== '') {
			return $header;
		}
		$auth = trim($this->request->getHeader('Authorization'));
		if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
			return trim($m[1]);
		}
		$param = $this->request->getParam('token', '');
		return is_string($param) ? trim($param) : '';
	}
}
