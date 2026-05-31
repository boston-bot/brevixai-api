<?php

namespace App\Services;

use App\Support\ProfessionalServicesDisclaimer;

class IrsNoticeExtractionService
{
    public function __construct(
        private readonly IrsTaxNoticeService $taxNoticeService,
        private readonly IrmKnowledgeService $irmService,
    ) {}

    /**
     * Extract structured fields from raw notice text and enrich with IRM sections.
     *
     * Calls IrsTaxNoticeService for LLM-based field extraction (notice_type,
     * deadline_days, risk_level, etc.), then chains into IrmKnowledgeService to
     * return source-backed IRM sections for the identified notice type.
     *
     * @return array{
     *     status: string,
     *     notice_type: string,
     *     deadline_days: int|null,
     *     deadline_description: string,
     *     required_action: string,
     *     risk_level: string,
     *     key_amount: float|null,
     *     summary: string,
     *     irm_search_topic: string|null,
     *     results: list<array<string, mixed>>,
     *     disclaimer: string
     * }
     */
    public function extract(string $noticeText, int $limit = 5): array
    {
        $extraction = $this->taxNoticeService->interpretNotice($noticeText);
        $noticeCode = $extraction['notice_type'];

        $irmSearchTopic = null;
        $irmSections = [];

        if ($noticeCode !== 'Unknown') {
            $irmResult = $this->irmService->explainNoticeType($noticeCode, $limit);
            $irmSections = $irmResult['results'] ?? [];
            $irmSearchTopic = $irmResult['query'] ?? null;
        }

        return [
            'status' => 'ok',
            'notice_type' => $extraction['notice_type'],
            'deadline_days' => $extraction['deadline_days'],
            'deadline_description' => $extraction['deadline_description'],
            'required_action' => $extraction['required_action'],
            'risk_level' => $extraction['risk_level'],
            'key_amount' => $extraction['key_amount'],
            'summary' => $extraction['summary'],
            'irm_search_topic' => $irmSearchTopic,
            'results' => $irmSections,
            'disclaimer' => ProfessionalServicesDisclaimer::TEXT,
        ];
    }
}
