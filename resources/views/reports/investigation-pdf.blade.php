<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Investigation Report: {{ $report['title'] }}</title>
    <style>
        body {
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            font-size: 9pt;
            color: #1a1a1a;
            margin: 30px 40px;
            line-height: 1.4;
        }
        .disclaimer-box {
            background-color: #fff8e1;
            border: 2px solid #f9a825;
            border-left: 6px solid #f57f17;
            padding: 12px 14px;
            margin-bottom: 28px;
        }
        .disclaimer-label {
            font-weight: bold;
            font-size: 9pt;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #e65100;
            margin-bottom: 4px;
        }
        .disclaimer-text {
            font-size: 9pt;
            color: #3e2723;
        }
        h1 {
            font-size: 15pt;
            color: #111;
            border-bottom: 2px solid #333;
            padding-bottom: 6px;
            margin-bottom: 4px;
        }
        .report-meta {
            font-size: 8pt;
            color: #555;
            margin-bottom: 24px;
        }
        h2 {
            font-size: 11pt;
            color: #222;
            border-bottom: 1px solid #ccc;
            padding-bottom: 3px;
            margin-top: 22px;
            margin-bottom: 8px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        th {
            background-color: #f0f0f0;
            text-align: left;
            padding: 5px 8px;
            font-size: 8pt;
            border: 1px solid #ccc;
            width: 28%;
            font-weight: bold;
        }
        td {
            padding: 5px 8px;
            font-size: 8.5pt;
            border: 1px solid #ccc;
            vertical-align: top;
        }
        .badge {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 3px;
            font-size: 7.5pt;
            font-weight: bold;
            text-transform: uppercase;
        }
        .badge-severity-critical  { background: #fde8e8; color: #c0392b; }
        .badge-severity-high      { background: #fef3e2; color: #d35400; }
        .badge-severity-warning   { background: #fefce1; color: #8a6d00; }
        .badge-severity-medium    { background: #fefce1; color: #8a6d00; }
        .badge-severity-low       { background: #edf7ee; color: #1e7e34; }
        .badge-status             { background: #e8f0fe; color: #1a56db; }
        .evidence-row td:first-child {
            font-weight: bold;
        }
        .empty-notice {
            color: #888;
            font-style: italic;
            padding: 4px 0;
            font-size: 8.5pt;
        }
        .timeline-actor {
            font-size: 7.5pt;
            color: #666;
        }
        .footer {
            margin-top: 36px;
            border-top: 1px solid #ddd;
            padding-top: 8px;
            font-size: 7.5pt;
            color: #888;
        }
    </style>
</head>
<body>

    {{-- DISCLAIMER: always shown prominently at the top --}}
    <div class="disclaimer-box">
        <div class="disclaimer-label">Important Notice</div>
        <div class="disclaimer-text">{{ $report['disclaimer'] }}</div>
    </div>

    {{-- Report header --}}
    <h1>Investigation Report: {{ $report['title'] }}</h1>
    <div class="report-meta">
        Generated: {{ $report['generated_at'] }}
        &nbsp;&nbsp;|&nbsp;&nbsp;
        Case ID: {{ $report['case_summary']['id'] ?? '—' }}
    </div>

    {{-- CASE SUMMARY --}}
    <h2>Case Summary</h2>
    <table>
        <tr>
            <th>Status</th>
            <td>
                <span class="badge badge-status">{{ $report['case_summary']['status'] ?? '—' }}</span>
            </td>
        </tr>
        <tr>
            <th>Severity</th>
            <td>
                @php $sev = $report['case_summary']['severity'] ?? 'unknown'; @endphp
                <span class="badge badge-severity-{{ strtolower($sev) }}">{{ $sev }}</span>
            </td>
        </tr>
        <tr>
            <th>Description</th>
            <td>{{ $report['case_summary']['description'] ?? '—' }}</td>
        </tr>
        <tr>
            <th>Created</th>
            <td>{{ $report['case_summary']['created_at'] ?? '—' }}</td>
        </tr>
        @if(!empty($report['case_summary']['resolved_at']))
        <tr>
            <th>Resolved</th>
            <td>{{ $report['case_summary']['resolved_at'] }}</td>
        </tr>
        @endif
        @if(!empty($report['case_summary']['resolution_notes']))
        <tr>
            <th>Resolution Notes</th>
            <td>{{ $report['case_summary']['resolution_notes'] }}</td>
        </tr>
        @endif
    </table>

    {{-- RISK INDICATORS --}}
    <h2>Risk Indicators</h2>
    <table>
        <tr>
            <th>Case Type</th>
            <td>{{ $report['risk_summary']['case_type'] ?? '—' }}</td>
        </tr>
        <tr>
            <th>Severity</th>
            <td>
                @php $riskSev = $report['risk_summary']['severity'] ?? null; @endphp
                @if($riskSev)
                    <span class="badge badge-severity-{{ strtolower($riskSev) }}">{{ $riskSev }}</span>
                @else
                    —
                @endif
            </td>
        </tr>
        <tr>
            <th>Confidence Score</th>
            <td>
                @if(!is_null($report['risk_summary']['confidence_score'] ?? null))
                    {{ number_format((float) $report['risk_summary']['confidence_score'] * 100, 1) }}%
                @else
                    —
                @endif
            </td>
        </tr>
        <tr>
            <th>Source Risk Domains</th>
            <td>
                @if(!empty($report['risk_summary']['source_risk_domains']))
                    {{ implode(', ', (array) $report['risk_summary']['source_risk_domains']) }}
                @else
                    —
                @endif
            </td>
        </tr>
        <tr>
            <th>Linked Alerts</th>
            <td>{{ $report['risk_summary']['linked_alert_count'] ?? 0 }}</td>
        </tr>
    </table>

    {{-- INVESTIGATIVE SYNTHESIS --}}
    <h2>Investigative Synthesis</h2>
    <table>
        <tr>
            <th>Investigation Status</th>
            <td>
                <span class="badge badge-status">{{ $report['investigative_synthesis']['investigation_status'] ?? '—' }}</span>
            </td>
        </tr>
        <tr>
            <th>Priority</th>
            <td>{{ $report['investigative_synthesis']['investigation_priority'] ?? '—' }}</td>
        </tr>
        <tr>
            <th>Investigation Summary</th>
            <td>{{ $report['investigative_synthesis']['investigation_summary'] ?? '—' }}</td>
        </tr>
        <tr>
            <th>Last Activity</th>
            <td>{{ $report['investigative_synthesis']['last_activity_at'] ?? '—' }}</td>
        </tr>
        <tr>
            <th>Recommendation Status</th>
            <td>{{ $report['investigative_synthesis']['recommendation_status'] ?? '—' }}</td>
        </tr>
        @if(!empty($report['investigative_synthesis']['recommendation_summary']))
        <tr>
            <th>Recommendation Summary</th>
            <td>{{ $report['investigative_synthesis']['recommendation_summary'] }}</td>
        </tr>
        @endif
    </table>

    {{-- EVIDENCE ITEMS --}}
    <h2>Evidence Items ({{ count($report['evidence_items'] ?? []) }})</h2>
    @if(empty($report['evidence_items']))
        <p class="empty-notice">No evidence items recorded.</p>
    @else
        <table>
            <tr>
                <th style="width:20%">Type</th>
                <th style="width:30%">Title</th>
                <th style="width:50%">Summary</th>
            </tr>
            @foreach($report['evidence_items'] as $item)
            <tr>
                <td><span class="badge badge-status">{{ $item['evidence_type'] ?? '—' }}</span></td>
                <td>{{ $item['title'] ?? '—' }}</td>
                <td>{{ $item['summary'] ?? '—' }}</td>
            </tr>
            @endforeach
        </table>
    @endif

    {{-- ACTIVITY TIMELINE --}}
    <h2>Activity Timeline ({{ count($report['activity_timeline'] ?? []) }})</h2>
    @if(empty($report['activity_timeline']))
        <p class="empty-notice">No activity recorded.</p>
    @else
        <table>
            <tr>
                <th style="width:22%">Event</th>
                <th style="width:38%">Summary</th>
                <th style="width:20%">Actor</th>
                <th style="width:20%">Date</th>
            </tr>
            @foreach($report['activity_timeline'] as $event)
            <tr>
                <td>{{ $event['event_type'] ?? '—' }}</td>
                <td>{{ $event['event_summary'] ?? '—' }}</td>
                <td class="timeline-actor">
                    {{ $event['actor_type'] ?? '—' }}
                    @if(!empty($event['actor_id']))
                        <br>{{ $event['actor_id'] }}
                    @endif
                </td>
                <td>{{ $event['created_at'] ?? '—' }}</td>
            </tr>
            @endforeach
        </table>
    @endif

    {{-- NOTES --}}
    @if(!empty($report['notes']))
    <h2>Notes</h2>
    @foreach($report['notes'] as $note)
    <table>
        <tr>
            <th style="width:28%">Type</th>
            <td>{{ $note['type'] ?? '—' }}</td>
        </tr>
        <tr>
            <th>Content</th>
            <td>{{ $note['content'] ?? '—' }}</td>
        </tr>
    </table>
    @endforeach
    @endif

    <div class="footer">
        Brevix AI &mdash; Investigation Report &mdash; {{ $report['generated_at'] }}
        &nbsp;&nbsp;|&nbsp;&nbsp;
        {{ $report['disclaimer'] }}
    </div>

</body>
</html>
