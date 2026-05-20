<?php

namespace Tests\Unit\PersonalFinance;

use App\Services\PersonalFinance\ChaseStatementParser;
use PHPUnit\Framework\TestCase;

class ChaseStatementParserTest extends TestCase
{
    public function test_parses_chase_sections_and_multiline_descriptions(): void
    {
        $text = <<<'TEXT'
CHASE TOTAL CHECKING
January 1, 2026 through January 31, 2026
Account Number: 0000000059

DEPOSITS AND ADDITIONS
Date Description Amount Balance
01/03 ACME PAYROLL DIRECT DEP 5,000.00 5,100.00

ATM & DEBIT CARD WITHDRAWALS
Date Description Amount Balance
01/04 POS DEBIT STARBUCKS STORE
SEATTLE WA 7.50 5,092.50

ELECTRONIC WITHDRAWALS
01/05 CHASE CREDIT CRD AUTOPAY 1,200.00 3,892.50

FEES
01/31 MONTHLY SERVICE FEE 12.00 3,880.50
TEXT;

        $parsed = (new ChaseStatementParser)->parse($text, '20260131-statements-0059-.pdf');

        $this->assertSame('2026-01-31', $parsed['statement_date']);
        $this->assertSame('2026-01-01', $parsed['period_start']);
        $this->assertSame('2026-01-31', $parsed['period_end']);
        $this->assertSame('0059', $parsed['account_last4']);
        $this->assertCount(4, $parsed['transactions']);

        $this->assertSame(5000.00, $parsed['transactions'][0]['amount']);
        $this->assertSame('inflow', $parsed['transactions'][0]['direction']);

        $this->assertSame('POS DEBIT STARBUCKS STORE SEATTLE WA', $parsed['transactions'][1]['description']);
        $this->assertSame(-7.50, $parsed['transactions'][1]['amount']);
        $this->assertSame('outflow', $parsed['transactions'][1]['direction']);

        $this->assertSame(-1200.00, $parsed['transactions'][2]['amount']);
        $this->assertSame(-12.00, $parsed['transactions'][3]['amount']);
    }

    public function test_uses_filename_period_when_statement_period_is_absent(): void
    {
        $text = <<<'TEXT'
DEPOSITS AND ADDITIONS
01/03 ACME PAYROLL DIRECT DEP 5,000.00 5,100.00
TEXT;

        $parsed = (new ChaseStatementParser)->parse($text, '20260227-statements-0059-.pdf');

        $this->assertSame('2026-02-27', $parsed['statement_date']);
        $this->assertSame('2026-02-01', $parsed['period_start']);
        $this->assertSame('2026-02-27', $parsed['period_end']);
        $this->assertSame('0059', $parsed['account_last4']);
    }
}
