<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title ?? 'Inventory' }} - {{ config('app.name', 'Warehouse Platform') }}</title>
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
        @fluxAppearance
        <style>
            :root {
                color-scheme: light;
                font-family: "Instrument Sans", ui-sans-serif, system-ui, sans-serif;
                --ink: #17202a;
                --muted: #667085;
                --line: #d8dee8;
                --page: #f4f7fb;
                --panel: #ffffff;
                --accent: #146c5f;
                --accent-soft: #e6f4f1;
                --warning: #9a5b00;
                --warning-soft: #fff4d6;
                --danger: #b42318;
                --danger-soft: #fee4e2;
            }

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                min-height: 100vh;
                background: var(--page);
                color: var(--ink);
            }

            [data-flux-icon] {
                width: 1rem;
                height: 1rem;
                flex-shrink: 0;
            }

            .page {
                width: min(1180px, calc(100% - 32px));
                margin: 0 auto;
                padding: 32px 0;
            }

            .page-header {
                display: flex;
                align-items: end;
                justify-content: space-between;
                gap: 24px;
                margin-bottom: 20px;
            }

            .eyebrow {
                margin: 0 0 6px;
                color: var(--accent);
                font-size: 13px;
                font-weight: 700;
                text-transform: uppercase;
            }

            h1 {
                margin: 0;
                font-size: clamp(28px, 4vw, 42px);
                line-height: 1.1;
                letter-spacing: 0;
            }

            .page-header p:last-child {
                margin: 8px 0 0;
                color: var(--muted);
            }

            .summary-grid {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 12px;
                margin-bottom: 16px;
            }

            .summary-card,
            .table-shell {
                background: var(--panel);
                border: 1px solid var(--line);
                border-radius: 8px;
                box-shadow: 0 1px 2px rgb(16 24 40 / 6%);
            }

            .summary-card {
                padding: 16px;
            }

            .summary-card span {
                display: block;
                color: var(--muted);
                font-size: 13px;
                font-weight: 700;
                text-transform: uppercase;
            }

            .summary-card strong {
                display: block;
                margin-top: 8px;
                font-size: 26px;
            }

            .table-toolbar {
                display: grid;
                grid-template-columns: minmax(260px, 1fr) 220px;
                gap: 12px;
                padding: 16px;
                border-bottom: 1px solid var(--line);
            }

            label span {
                display: block;
                margin-bottom: 6px;
                color: var(--muted);
                font-size: 13px;
                font-weight: 700;
            }

            input,
            select {
                width: 100%;
                min-height: 42px;
                border: 1px solid var(--line);
                border-radius: 6px;
                background: #fff;
                color: var(--ink);
                padding: 9px 11px;
                outline: none;
            }

            input:focus,
            select:focus {
                border-color: var(--accent);
                box-shadow: 0 0 0 3px var(--accent-soft);
            }

            .table-wrap {
                overflow-x: auto;
            }

            table {
                width: 100%;
                min-width: 980px;
                border-collapse: collapse;
            }

            th,
            td {
                padding: 12px 14px;
                border-bottom: 1px solid var(--line);
                text-align: left;
                vertical-align: middle;
            }

            th {
                background: #f8fafc;
                color: var(--muted);
                font-size: 12px;
                text-transform: uppercase;
            }

            th button {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                border: 0;
                background: transparent;
                color: inherit;
                cursor: pointer;
                font: inherit;
                padding: 0;
            }

            tbody tr:hover {
                background: #fbfcfe;
            }

            .numeric {
                text-align: right;
            }

            .sku {
                color: var(--accent);
                font-weight: 700;
            }

            .subtle {
                display: block;
                margin-top: 4px;
                color: var(--muted);
                font-size: 12px;
                line-height: 1.35;
                white-space: normal;
            }

            .available {
                font-weight: 800;
                border-radius: 6px;
                padding: 3px 7px;
            }

            .available-success {
                background: var(--accent-soft);
                color: var(--accent);
            }

            .available-warning {
                background: var(--warning-soft);
                color: var(--warning);
            }

            .available-danger {
                background: var(--danger-soft);
                color: var(--danger);
            }

            .inventory-toolbar {
                grid-template-columns: repeat(6, minmax(0, 1fr));
                align-items: end;
            }

            .inventory-toolbar > :first-child {
                grid-column: span 2;
            }

            .sku-list {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 6px;
                min-width: 0;
                margin-top: 10px;
                white-space: normal;
            }

            .stock-item-cell {
                width: 30%;
                min-width: 240px;
                max-width: 340px;
            }

            .sku-chip {
                display: inline-flex;
                flex-direction: column;
                gap: 2px;
                max-width: 160px;
                border: 1px solid var(--line);
                border-radius: 6px;
                background: #fbfcfe;
                color: var(--ink);
                padding: 6px 8px;
                font-size: 12px;
                font-weight: 800;
                vertical-align: top;
            }

            .inventory-table {
                min-width: 980px;
                table-layout: auto;
            }

            .inventory-table th,
            .inventory-table td {
                padding-left: 10px;
                padding-right: 10px;
            }

            .inventory-table th {
                white-space: nowrap;
            }

            .inventory-table th:first-child {
                min-width: 240px;
            }

            .inventory-table .stock-item-cell strong,
            .inventory-table .stock-item-cell span,
            .inventory-table td strong,
            .inventory-table td .subtle {
                overflow-wrap: anywhere;
            }

            .inventory-table .exceptions-cell {
                width: 116px;
            }

            .sku-chip small {
                color: var(--muted);
                font-size: 11px;
                font-weight: 600;
                overflow-wrap: anywhere;
            }

            .more-link,
            .action-link {
                display: inline-flex;
                align-items: center;
                min-height: 30px;
                border: 1px solid var(--line);
                border-radius: 6px;
                background: #fff;
                color: var(--accent);
                cursor: pointer;
                font: inherit;
                font-size: 12px;
                font-weight: 800;
                padding: 5px 8px;
                text-decoration: none;
                white-space: nowrap;
            }

            .more-link:hover,
            .action-link:hover {
                border-color: var(--accent);
                background: var(--accent-soft);
            }

            .exceptions-cell {
                min-width: 110px;
            }

            .exception-badge {
                display: inline-flex;
                align-items: center;
                margin: 0 4px 4px 0;
                border-radius: 999px;
                background: var(--warning-soft);
                color: var(--warning);
                font-size: 11px;
                font-weight: 800;
                line-height: 1;
                padding: 5px 8px;
                white-space: nowrap;
            }

            .exception-badge.danger {
                background: var(--danger-soft);
                color: var(--danger);
            }

            .muted-dash {
                color: var(--muted);
            }

            .flux-panel {
                padding: 16px;
                overflow: hidden;
            }

            ui-table-scroll-area {
                display: block;
                overflow-x: auto;
            }

            .movement-toolbar {
                display: grid;
                grid-template-columns: minmax(260px, 2fr) repeat(4, minmax(150px, 1fr));
                gap: 12px;
                align-items: end;
                margin-bottom: 14px;
            }

            .movement-toolbar > :last-child {
                align-self: end;
            }

            .active-filter-row {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 8px;
                margin-bottom: 12px;
            }

            .latest-movement-row {
                display: inline-flex;
                align-items: baseline;
                gap: 10px;
                margin: -4px 0 14px;
                color: var(--muted);
                font-size: 13px;
                font-weight: 700;
                text-transform: uppercase;
            }

            .latest-movement-row strong {
                color: var(--ink);
                font-size: 15px;
                text-transform: none;
            }

            .movement-table {
                min-width: 0;
                table-layout: fixed;
            }

            .movement-table th,
            .movement-table td {
                padding-left: 12px;
                padding-right: 12px;
            }

            .movement-table th {
                white-space: nowrap;
            }

            .movement-created-cell {
                width: 86px;
            }

            .movement-created-cell strong,
            .movement-created-cell span {
                display: block;
                white-space: nowrap;
            }

            .movement-created-cell strong {
                color: var(--ink);
                font-size: 13px;
                line-height: 1.25;
            }

            .movement-created-cell span {
                margin-top: 2px;
                color: var(--muted);
                font-size: 12px;
            }

            .movement-stock-cell {
                width: 30%;
            }

            .movement-stock-cell strong,
            .movement-stock-cell span,
            .movement-stock-cell small {
                display: block;
            }

            .movement-stock-cell strong {
                color: var(--ink);
                font-size: 13px;
                line-height: 1.25;
            }

            .movement-stock-cell span {
                margin-top: 3px;
                color: var(--muted);
                font-size: 12px;
                line-height: 1.3;
            }

            .movement-stock-cell small {
                margin-top: 5px;
                color: var(--muted);
                font-size: 11px;
                line-height: 1.25;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .movement-change-cell {
                width: 170px;
            }

            .bucket-list {
                display: flex;
                flex-direction: column;
                gap: 4px;
                margin-top: 8px;
                min-width: 0;
            }

            .bucket-list span {
                display: grid;
                grid-template-columns: minmax(62px, 1fr) auto;
                align-items: baseline;
                gap: 8px;
                color: var(--muted);
                font-size: 12px;
                line-height: 1.2;
                white-space: nowrap;
            }

            .bucket-list strong {
                color: var(--ink);
                font-size: 13px;
            }

            .bucket-list-end {
                align-items: stretch;
                margin-top: 0;
            }

            .reference-cell {
                display: block;
                max-width: 150px;
            }

            .reference-cell strong,
            .reference-cell span {
                display: block;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .reference-cell strong {
                color: var(--ink);
                font-size: 12px;
                line-height: 1.25;
            }

            .reference-cell span {
                margin-top: 3px;
                color: var(--muted);
                font-size: 12px;
                line-height: 1.25;
            }

            .movement-actor-cell {
                width: 24%;
            }

            .movement-actor-cell strong,
            .movement-actor-cell span {
                display: block;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .movement-actor-cell strong {
                color: var(--ink);
                font-size: 13px;
                line-height: 1.25;
            }

            .movement-actor-cell span {
                margin-top: 4px;
                color: var(--muted);
                font-size: 12px;
                line-height: 1.3;
            }

            .delta-positive {
                color: #067647;
                font-weight: 800;
            }

            .delta-negative {
                color: var(--danger);
                font-weight: 800;
            }

            .delta-zero {
                color: var(--muted);
                font-weight: 800;
            }

            .summary-date {
                font-size: 22px;
            }

            .badge {
                display: inline-flex;
                align-items: center;
                border-radius: 999px;
                font-size: 12px;
                font-weight: 700;
                padding: 5px 10px;
            }

            .badge-success {
                background: var(--accent-soft);
                color: var(--accent);
            }

            .badge-warning {
                background: var(--warning-soft);
                color: var(--warning);
            }

            .badge-danger {
                background: var(--danger-soft);
                color: var(--danger);
            }

            .empty-state {
                padding: 32px 16px;
                color: var(--muted);
                text-align: center;
            }

            .pagination-row {
                padding: 14px 16px;
            }

            .pagination-row nav > div:first-child {
                margin-bottom: 10px;
            }

            .sku-page-actions,
            .form-panel-header,
            .form-actions {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
            }

            .sku-page-actions {
                margin-bottom: 14px;
            }

            .sku-page-actions strong,
            .form-panel-header strong {
                display: block;
                font-size: 16px;
            }

            .sku-page-actions span,
            .form-panel-header span {
                display: block;
                margin-top: 3px;
                color: var(--muted);
                font-size: 13px;
            }

            .status-message {
                margin-bottom: 14px;
                border: 1px solid #b7e4d4;
                border-radius: 6px;
                background: #ecfdf3;
                color: #067647;
                font-weight: 700;
                padding: 10px 12px;
            }

            .sku-toolbar {
                display: grid;
                grid-template-columns: minmax(260px, 2fr) repeat(5, minmax(130px, 1fr));
                gap: 12px;
                align-items: end;
                margin-bottom: 14px;
            }

            .sku-table {
                min-width: 0;
                table-layout: fixed;
            }

            .sku-table th,
            .sku-table td {
                padding-left: 10px;
                padding-right: 10px;
            }

            .sku-primary-cell strong,
            .sku-primary-cell span,
            .sku-primary-cell small,
            .sku-stock-cell strong,
            .sku-stock-cell span,
            .sku-stock-cell small,
            .sku-muted-cell strong,
            .sku-muted-cell span,
            .sku-muted-cell small,
            .sku-platform-cell span,
            .sku-platform-cell small {
                display: block;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .sku-primary-cell strong,
            .sku-stock-cell strong,
            .sku-muted-cell strong {
                color: var(--ink);
                font-size: 13px;
                line-height: 1.25;
            }

            .sku-primary-cell span,
            .sku-stock-cell span,
            .sku-muted-cell span,
            .sku-platform-cell span {
                margin-top: 3px;
                color: var(--muted);
                font-size: 12px;
            }

            .sku-primary-cell small,
            .sku-stock-cell small,
            .sku-muted-cell small,
            .sku-platform-cell small {
                margin-top: 3px;
                color: var(--muted);
                font-size: 11px;
            }

            .sku-form {
                display: grid;
                gap: 14px;
            }

            .form-panel {
                display: grid;
                gap: 14px;
            }

            .form-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
                align-items: start;
            }

            .form-grid.three {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .form-grid-spaced {
                margin-top: 12px;
            }

            .form-grid-wide {
                grid-column: 1 / -1;
            }

            textarea {
                width: 100%;
                border: 1px solid var(--line);
                border-radius: 6px;
                background: #fff;
                color: var(--ink);
                font: inherit;
                padding: 9px 11px;
                resize: vertical;
            }

            textarea:focus {
                border-color: var(--accent);
                box-shadow: 0 0 0 3px var(--accent-soft);
                outline: none;
            }

            .segmented-row {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
            }

            .segmented-row label,
            .checkbox-stack label {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                border: 1px solid var(--line);
                border-radius: 6px;
                background: #fff;
                min-height: 38px;
                padding: 8px 10px;
                font-size: 13px;
                font-weight: 700;
            }

            .checkbox-stack {
                display: grid;
                gap: 8px;
            }

            .form-error {
                margin: -4px 0 0;
                color: var(--danger);
                font-size: 12px;
                font-weight: 700;
            }

            @media (max-width: 720px) {
                .page {
                    width: min(100% - 20px, 1180px);
                    padding: 20px 0;
                }

                .page-header {
                    display: block;
                }

                .summary-grid,
                .table-toolbar,
                .movement-toolbar,
                .sku-toolbar,
                .form-grid,
                .form-grid.three {
                    grid-template-columns: 1fr;
                }

                .sku-page-actions,
                .form-panel-header,
                .form-actions {
                    align-items: stretch;
                    flex-direction: column;
                }

                .inventory-toolbar > :first-child {
                    grid-column: auto;
                }
            }

            @media (max-width: 980px) {
                .summary-grid {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }
        </style>
    </head>
    <body>
        <main class="page">
            <header class="page-header">
                <div>
                    <p class="eyebrow">Warehouse Platform</p>
                    <h1>{{ $title ?? 'Inventory' }}</h1>
                    <p>{{ $subtitle ?? 'Track stock levels, reorder risk, and warehouse locations.' }}</p>
                </div>
            </header>

            {{ $slot }}
        </main>

        @livewireScripts
        @fluxScripts
    </body>
</html>
