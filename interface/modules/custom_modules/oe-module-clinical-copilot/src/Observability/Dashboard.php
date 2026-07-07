<?php

/**
 * Dashboard — the in-app observability surface (§3.3, R4).
 *
 * Renders the metrics tiles (Metrics over TraceReader) and the click-through path:
 * tile → request list → span waterfall → span payload. ACL-gated (admin) and every view
 * is audit-logged (§3.2 — access to the trace UI is itself audit-logged). Escaping is via
 * the Twig |text/|attr/xlt sinks (autoescape is OFF globally); no model/user data is
 * ever rendered raw. This class is framework-coupled (Twig + audit) so it is validated by
 * php -l and a stack-required PHPUnit test; the maths it renders is the isolated Metrics.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Logging\SystemLogger;
use Twig\Environment;

final class Dashboard
{
    public function __construct(
        private readonly TraceReader $traces,
        private readonly Environment $twig,
        private readonly ?SystemLogger $logger = null,
    ) {
    }

    /**
     * Overview: every metric tile for the window, plus any firing-alert banner.
     *
     * @param list<Alert> $alerts
     */
    public function renderOverview(string $sinceIso, int $userId, array $alerts = []): string
    {
        $this->audit('overview', $userId);
        $rows = $this->traces->windowSpans($sinceIso);
        return $this->twig->render('dashboard.html.twig', [
            'metrics' => Metrics::summary($rows),
            'since' => $sinceIso,
            'alerts' => array_map(static fn(Alert $a): array => [
                'id' => $a->id->value,
                'label' => $a->id->label(),
                'severity' => $a->severity->value,
                'message' => $a->message,
                'observed' => $a->observed,
                'threshold' => $a->threshold,
            ], $alerts),
        ]);
    }

    /**
     * Request list for a tile: one row per correlation id (optionally filtered by kind).
     */
    public function renderRequestList(string $sinceIso, ?string $kind, int $userId): string
    {
        $this->audit('request_list', $userId);
        return $this->twig->render('dashboard_requests.html.twig', [
            'requests' => $this->traces->requestList($sinceIso, $kind),
            'kind' => $kind,
            'since' => $sinceIso,
        ]);
    }

    /**
     * Span waterfall for a single request (correlation id), depth-indented by parent.
     */
    public function renderWaterfall(string $correlationId, int $userId): string
    {
        $this->audit('waterfall', $userId);
        $spans = $this->traces->waterfall($correlationId);
        return $this->twig->render('dashboard_waterfall.html.twig', [
            'correlation_id' => $correlationId,
            'spans' => $this->withDepth($spans),
        ]);
    }

    /**
     * Full payload for one span (the deepest drill-down).
     */
    public function renderPayload(string $spanId, int $userId): string
    {
        $this->audit('payload', $userId);
        return $this->twig->render('dashboard_payload.html.twig', [
            'span' => $this->traces->span($spanId),
        ]);
    }

    /**
     * Annotate each span with a nesting depth for waterfall indentation.
     *
     * @param list<array<string, mixed>> $spans
     * @return list<array<string, mixed>>
     */
    private function withDepth(array $spans): array
    {
        $depthBySpan = [];
        foreach ($spans as &$span) {
            $parent = $span['parent_span_id'] ?? null;
            $depth = 0;
            if (is_string($parent) && isset($depthBySpan[$parent])) {
                $depth = $depthBySpan[$parent] + 1;
            }
            $spanId = (string) ($span['span_id'] ?? '');
            if ($spanId !== '') {
                $depthBySpan[$spanId] = $depth;
            }
            $span['depth'] = $depth;
        }
        unset($span);
        return $spans;
    }

    private function audit(string $view, int $userId): void
    {
        try {
            EventAuditLogger::getInstance()->newEvent(
                'copilot-dashboard-view',
                (string) $userId,
                '',
                1,
                'Clinical Co-Pilot observability dashboard view: ' . $view,
            );
        } catch (\Throwable $e) {
            // Auditing must not take the dashboard down; record and continue.
            $this->logger?->error('Clinical Co-Pilot dashboard audit failed', [
                'view' => $view,
                'exception' => $e,
            ]);
        }
    }
}
