<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ChatController;
use App\Services\ChatService;
use App\Services\RexChatRouterService;
use App\Support\ProfessionalServicesDisclaimer;
use ReflectionClass;
use Tests\TestCase;

class PositioningGuardrailTest extends TestCase
{
    public function test_rex_direct_prompt_uses_orchestration_and_professional_services_boundaries(): void
    {
        $controller = new ChatController(app(ChatService::class));
        $prompt = $this->privateMethod($controller, 'rexSystemPrompt');

        $this->assertStringContainsString('financial intelligence orchestration layer', $prompt);
        $this->assertStringContainsString('Do not provide legal, tax, accounting, audit-opinion, CPA, investment, law-enforcement, or attorney-client services.', $prompt);
        $this->assertStringContainsString('Do not conclude fraud occurred', $prompt);
        $this->assertStringNotContainsString('financial auditor', $prompt);
    }

    public function test_rex_router_direct_mode_is_narrow_and_not_general_accounting_guidance(): void
    {
        $router = app(RexChatRouterService::class);
        $prompt = $this->privateMethod($router, 'systemPrompt');

        $this->assertStringContainsString('financial intelligence orchestration layer', $prompt);
        $this->assertStringContainsString('answer only product navigation, data-source setup, or risk-workflow questions', $prompt);
        $this->assertStringContainsString('Do not classify legal, tax, accounting, audit-opinion, CPA, investment, law-enforcement, or attorney-client requests as direct professional guidance.', $prompt);
        $this->assertStringNotContainsString('general accounting, audit', $prompt);
    }

    public function test_shared_professional_services_disclaimer_covers_required_boundaries(): void
    {
        $disclaimer = ProfessionalServicesDisclaimer::TEXT;

        foreach (['legal', 'tax', 'accounting', 'audit', 'CPA', 'investment', 'law-enforcement', 'attorney-client'] as $term) {
            $this->assertStringContainsString($term, $disclaimer);
        }

        $this->assertStringContainsString('not proof of fraud', $disclaimer);
        $this->assertStringContainsString('not', strtolower($disclaimer));
    }

    private function privateMethod(object $object, string $method): string
    {
        $reflection = new ReflectionClass($object);
        $reflectedMethod = $reflection->getMethod($method);
        $reflectedMethod->setAccessible(true);

        return (string) $reflectedMethod->invoke($object);
    }
}
