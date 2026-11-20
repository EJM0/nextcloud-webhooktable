<?php

declare(strict_types=1);

namespace OCA\WebhookTable\BackgroundJob;

use OCA\WebhookTable\Service\EndpointService;
use OCA\WebhookTable\Service\SubmissionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Trägt Einsendungen nach, die wegen einer gesperrten Zieldatei warten mussten,
 * sobald die Sperre aufgehoben ist. Läuft bei jedem Cron-Durchlauf (höchstens minütlich).
 */
class FlushQueue extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private EndpointService $endpoints,
		private SubmissionService $submissions,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(60);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
	}

	protected function run($argument): void {
		foreach ($this->endpoints->idsWithQueue() as $id) {
			$endpoint = $this->endpoints->find($id);
			if ($endpoint === null) {
				continue;
			}
			try {
				$written = $this->submissions->flushQueue($endpoint);
				if ($written > 0) {
					// gezählt wurden die Einsendungen schon beim Eingang
					$this->logger->info('Webhook-Tabelle: wartende Einsendungen nachgetragen', ['endpoint' => $id, 'count' => $written]);
				}
			} catch (\Throwable $e) {
				$this->endpoints->recordError($id, 'Nachtragen fehlgeschlagen: ' . $e->getMessage());
				$this->logger->error('Webhook-Tabelle: Nachtragen fehlgeschlagen', ['exception' => $e, 'endpoint' => $id]);
			}
		}
	}
}
