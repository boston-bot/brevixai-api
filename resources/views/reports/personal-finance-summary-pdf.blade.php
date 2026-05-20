<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Personal Finance Summary</title>
    <style>
        body {
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            font-size: 9pt;
            color: #1a1a1a;
            margin: 30px 40px;
            line-height: 1.35;
        }
        h1 {
            font-size: 16pt;
            margin: 0 0 4px;
            border-bottom: 2px solid #222;
            padding-bottom: 6px;
        }
        h2 {
            font-size: 11pt;
            margin: 20px 0 8px;
            border-bottom: 1px solid #ccc;
            padding-bottom: 3px;
        }
        .meta {
            color: #666;
            font-size: 8pt;
            margin-bottom: 16px;
        }
        .notice {
            background: #fff8e1;
            border-left: 5px solid #d97706;
            padding: 9px 12px;
            margin: 14px 0 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 5px 7px;
            vertical-align: top;
        }
        th {
            background: #f2f2f2;
            text-align: left;
            font-weight: bold;
        }
        .amount {
            text-align: right;
            white-space: nowrap;
        }
        .warning {
            color: #8a4b00;
        }
        .footer {
            margin-top: 24px;
            padding-top: 8px;
            border-top: 1px solid #ddd;
            color: #777;
            font-size: 7.5pt;
        }
    </style>
</head>
<body>
    <h1>Personal Finance Summary</h1>
    <div class="meta">
        Generated: {{ $summary['generatedAt'] ?? now()->toIso8601String() }}
        @if(!empty($summary['scope']['from']) || !empty($summary['scope']['to']))
            | Period: {{ $summary['scope']['from'] ?? 'start' }} to {{ $summary['scope']['to'] ?? 'end' }}
        @endif
    </div>

    <div class="notice">
        This is cash-flow analysis for the Chase account only, not a complete household accounting report.
        Credit card payments are opaque unless card statements are imported separately.
    </div>

    <h2>Totals</h2>
    <table>
        <tr><th>Income</th><td class="amount">${{ number_format((float) ($summary['totals']['income'] ?? 0), 2) }}</td></tr>
        <tr><th>Outflow</th><td class="amount">${{ number_format((float) ($summary['totals']['outflow'] ?? 0), 2) }}</td></tr>
        <tr><th>Net Cash Flow</th><td class="amount">${{ number_format((float) ($summary['totals']['netCashFlow'] ?? 0), 2) }}</td></tr>
        <tr><th>Average Monthly Outflow</th><td class="amount">${{ number_format((float) ($summary['totals']['averageMonthlyOutflow'] ?? 0), 2) }}</td></tr>
        <tr><th>Average Monthly Deficit</th><td class="amount">${{ number_format((float) ($summary['totals']['averageMonthlyDeficit'] ?? 0), 2) }}</td></tr>
        <tr><th>Cumulative Deficit</th><td class="amount">${{ number_format((float) ($summary['totals']['cumulativeDeficit'] ?? 0), 2) }}</td></tr>
    </table>

    <h2>Monthly Cash Flow</h2>
    <table>
        <tr>
            <th>Month</th>
            <th class="amount">Income</th>
            <th class="amount">Outflow</th>
            <th class="amount">Net</th>
        </tr>
        @forelse($summary['monthlyTrend'] ?? [] as $month)
            <tr>
                <td>{{ $month['month'] }}</td>
                <td class="amount">${{ number_format((float) $month['income'], 2) }}</td>
                <td class="amount">${{ number_format((float) $month['outflow'], 2) }}</td>
                <td class="amount">${{ number_format((float) $month['netCashFlow'], 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="4">No monthly data available.</td></tr>
        @endforelse
    </table>

    <h2>Top Categories</h2>
    <table>
        <tr>
            <th>Category</th>
            <th class="amount">Amount</th>
            <th class="amount">Count</th>
            <th>Adjustable</th>
        </tr>
        @forelse($summary['topCategories'] ?? [] as $category)
            <tr>
                <td>{{ $category['category'] }}</td>
                <td class="amount">${{ number_format((float) $category['amount'], 2) }}</td>
                <td class="amount">{{ $category['count'] }}</td>
                <td>{{ $category['adjustable'] ? 'Yes' : 'No' }}</td>
            </tr>
        @empty
            <tr><td colspan="4">No category data available.</td></tr>
        @endforelse
    </table>

    <h2>Top Merchants</h2>
    <table>
        <tr>
            <th>Merchant</th>
            <th class="amount">Amount</th>
            <th class="amount">Count</th>
        </tr>
        @forelse($summary['topMerchants'] ?? [] as $merchant)
            <tr>
                <td>{{ $merchant['merchant'] }}</td>
                <td class="amount">${{ number_format((float) $merchant['amount'], 2) }}</td>
                <td class="amount">{{ $merchant['count'] }}</td>
            </tr>
        @empty
            <tr><td colspan="3">No merchant data available.</td></tr>
        @endforelse
    </table>

    <h2>Recommendations</h2>
    @forelse($summary['recommendations'] ?? [] as $recommendation)
        <p>{{ $recommendation }}</p>
    @empty
        <p>No recommendations available.</p>
    @endforelse

    @if(!empty($summary['warnings']))
        <h2>Warnings</h2>
        @foreach($summary['warnings'] as $warning)
            <p class="warning">{{ $warning }}</p>
        @endforeach
    @endif

    @if($includeTransactions)
        <h2>Transactions</h2>
        <table>
            <tr>
                <th>Date</th>
                <th>Description</th>
                <th>Category</th>
                <th>Person</th>
                <th class="amount">Amount</th>
            </tr>
            @foreach($transactions as $transaction)
                <tr>
                    <td>{{ \Carbon\Carbon::parse($transaction->posted_date)->toDateString() }}</td>
                    <td>{{ $transaction->description }}</td>
                    <td>{{ $transaction->category }}</td>
                    <td>{{ $transaction->person_scope }}</td>
                    <td class="amount">${{ number_format((float) $transaction->amount, 2) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <div class="footer">
        Generated locally by Brevix personal finance analyzer. Raw statement text is not sent to any external LLM.
    </div>
</body>
</html>
