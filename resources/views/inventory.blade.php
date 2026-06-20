<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title ?? 'Inventory' }} - {{ config('app.name', 'KuraLinks') }}</title>
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

            /* Wide variant for data-heavy index/table pages. Forms and detail pages
               keep the narrower .page width for comfortable reading line length. */
            .page--wide {
                width: min(1440px, calc(100% - 32px));
            }

            .page-header {
                display: flex;
                align-items: end;
                justify-content: space-between;
                gap: 24px;
                margin-bottom: 12px;
            }

            .section-nav {
                display: flex;
                align-items: center;
                gap: 4px;
                flex-shrink: 0;
            }

            .section-nav-link {
                display: inline-flex;
                align-items: center;
                padding: 6px 14px;
                border-radius: 6px;
                border: 1px solid transparent;
                font-size: 13px;
                font-weight: 600;
                color: var(--muted);
                text-decoration: none;
                transition: color 0.1s, background 0.1s, border-color 0.1s;
            }

            .section-nav-link:hover {
                color: var(--ink);
                background: var(--surface);
                border-color: var(--line);
            }

            .section-nav-link.is-active {
                color: var(--color-teal-700);
                background: color-mix(in oklab, var(--color-teal-600), transparent 90%);
                border-color: color-mix(in oklab, var(--color-teal-600), transparent 70%);
            }

            [x-cloak] {
                display: none !important;
            }

            .top-nav {
                position: sticky;
                top: 0;
                z-index: 100;
                background: var(--panel);
                border-bottom: 1px solid var(--line);
                box-shadow: 0 1px 3px rgb(0 0 0 / 5%);
            }

            .top-nav-inner {
                display: flex;
                align-items: center;
                gap: 4px;
                width: min(1180px, calc(100% - 32px));
                margin: 0 auto;
                height: 52px;
            }

            .top-nav-brand {
                margin-right: 12px;
                color: var(--accent);
                font-size: 16px;
                font-weight: 800;
                letter-spacing: -0.01em;
                text-decoration: none;
                white-space: nowrap;
            }

            .top-nav-brand:hover {
                opacity: 0.8;
            }

            .top-nav-items {
                display: flex;
                align-items: stretch;
                gap: 2px;
                flex: 1;
            }

            .top-nav-item {
                position: relative;
                display: flex;
                align-items: stretch;
            }

            .top-nav-btn {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                border: none;
                border-bottom: 2px solid transparent;
                border-radius: 0;
                background: transparent;
                color: var(--muted);
                cursor: pointer;
                font: inherit;
                font-size: 15px;
                font-weight: 700;
                padding: 0 10px;
                text-decoration: none;
                white-space: nowrap;
                transition: color 0.1s, border-color 0.1s;
            }

            .top-nav-btn:hover {
                color: var(--ink);
            }

            .top-nav-btn.is-active {
                color: var(--accent);
                border-bottom-color: var(--accent);
            }

            .top-nav-chevron {
                width: 12px;
                height: 12px;
                flex-shrink: 0;
                transition: transform 0.15s;
            }

            .top-nav-chevron.is-open {
                transform: rotate(180deg);
            }

            .top-nav-dropdown {
                position: absolute;
                top: calc(100% + 6px);
                left: 0;
                min-width: 180px;
                background: var(--panel);
                border: 1px solid var(--line);
                border-radius: 8px;
                box-shadow: 0 4px 16px rgb(0 0 0 / 10%);
                padding: 4px;
                z-index: 200;
            }

            .top-nav-dropdown a {
                display: flex;
                align-items: center;
                min-height: 34px;
                border-radius: 5px;
                color: var(--ink);
                font-size: 13px;
                font-weight: 600;
                padding: 6px 10px;
                text-decoration: none;
                transition: background 0.1s, color 0.1s;
            }

            .top-nav-dropdown a:hover,
            .top-nav-dropdown a.is-active {
                background: var(--accent-soft);
                color: var(--accent);
                font-weight: 700;
            }

            .locale-switcher {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                flex-wrap: wrap;
                justify-content: flex-end;
                margin-left: 8px;
            }

            .locale-switcher form {
                margin: 0;
            }

            .locale-btn {
                min-height: 28px;
                border: 1px solid var(--line);
                border-radius: 6px;
                background: #fff;
                color: var(--muted);
                cursor: pointer;
                font: inherit;
                font-size: 12px;
                font-weight: 800;
                padding: 4px 8px;
            }

            .locale-btn:hover,
            .locale-btn--active {
                border-color: var(--accent);
                background: var(--accent-soft);
                color: var(--accent);
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

            input:not([type="checkbox"]):not([type="radio"]),
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

            input:not([type="checkbox"]):not([type="radio"]):focus,
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

            .sales-order-filter-grid {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 8px;
                margin-bottom: 12px;
            }

            .sales-order-filter-toolbar {
                align-items: center;
            }

            .filter-menu,
            .action-menu {
                position: relative;
                min-width: 0;
            }

            .filter-menu summary,
            .action-menu summary,
            .action-menu-disabled {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 8px;
                min-width: 0;
                border: 1px solid var(--line);
                border-radius: 6px;
                background: #fff;
                color: var(--ink);
                cursor: pointer;
                font-size: 13px;
                font-weight: 700;
                min-height: 38px;
                padding: 8px 9px;
                box-shadow: 0 1px 1px rgba(15, 23, 42, 0.03);
                list-style: none;
            }

            .filter-menu summary::-webkit-details-marker,
            .action-menu summary::-webkit-details-marker {
                display: none;
            }

            .filter-menu summary::after,
            .action-menu summary::after {
                color: var(--muted);
                content: "v";
                font-size: 12px;
                line-height: 1;
            }

            .filter-menu[open] summary,
            .filter-menu.is-active summary,
            .action-menu[open] summary {
                border-color: var(--accent);
                box-shadow: 0 0 0 3px var(--accent-soft);
            }

            .filter-menu.is-active summary {
                background: #e6f7f3;
                color: var(--accent);
            }

            .filter-menu summary span {
                color: var(--ink);
                font-size: 13px;
                font-weight: 700;
            }

            .filter-menu.is-active summary span {
                color: var(--accent);
            }

            .filter-menu.is-active summary span::before {
                display: inline-block;
                width: 6px;
                height: 6px;
                margin-right: 6px;
                border-radius: 999px;
                background: var(--accent);
                content: "";
                vertical-align: 1px;
            }

            .filter-menu summary strong {
                display: block;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                font-size: 12px;
            }

            .filter-panel,
            .action-menu-panel {
                position: absolute;
                z-index: 30;
                top: calc(100% + 6px);
                left: 0;
                min-width: 100%;
                width: max-content;
                max-width: min(360px, calc(100vw - 32px));
                border: 1px solid var(--line);
                border-radius: 6px;
                background: #fff;
                box-shadow: 0 16px 32px rgba(15, 23, 42, 0.16);
                padding: 8px;
            }

            .action-menu-panel {
                right: 0;
                left: auto;
                display: grid;
                min-width: 190px;
            }

            .filter-panel {
                display: grid;
                gap: 6px;
                max-height: 326px;
                overflow: auto;
            }

            .filter-panel.compact {
                max-height: 326px;
            }

            .filter-panel label,
            .date-range-options label,
            .print-waiting-toggle {
                display: flex;
                align-items: center;
                gap: 6px;
                color: var(--muted);
                font-size: 12px;
                font-weight: 700;
            }

            .print-waiting-toggle {
                align-items: center;
                border: 1px solid var(--line);
                border-radius: 6px;
                background: #fff;
                color: var(--ink);
                min-height: 40px;
                padding: 7px 10px;
            }

            .compact-filter-toggle {
                justify-content: center;
                min-height: 38px;
                padding: 8px 10px;
            }

            .print-waiting-toggle span {
                display: grid;
                gap: 1px;
            }

            .print-waiting-toggle small {
                color: var(--muted);
                font-size: 11px;
                font-weight: 700;
            }

            .print-ready-toggle-input {
                position: absolute;
                opacity: 0;
                pointer-events: none;
            }

            .print-ready-label {
                white-space: nowrap;
            }

            .print-ready-pill {
                justify-self: start;
                min-width: 0;
                border-color: var(--line);
                background: #fff;
                color: var(--ink);
                font-weight: 700;
                user-select: none;
            }

            .print-ready-pill.is-active {
                border-color: var(--accent);
                background: #e6f7f3;
                color: var(--accent);
                box-shadow: 0 0 0 3px var(--accent-soft);
            }

            .filter-helper {
                display: block;
                color: var(--muted);
                font-size: 11px;
                line-height: 1.25;
                padding: 0 6px 4px 28px;
            }

            .filter-panel label.is-disabled {
                cursor: not-allowed;
                opacity: 0.55;
            }

            .filter-panel label {
                min-width: 180px;
                border-radius: 5px;
                padding: 5px 6px;
            }

            .filter-panel label:hover,
            .action-menu-panel a:hover,
            .action-menu-panel button:hover {
                background: var(--accent-soft);
                color: var(--accent);
            }

            .sales-order-search-row {
                position: relative;
                flex: 1 1 270px;
                max-width: 320px;
                min-width: 240px;
            }

            .sales-order-search-input {
                width: 100%;
                min-height: 38px;
                border: 1px solid var(--line);
                border-radius: 6px;
                background: #fff;
                color: var(--ink);
                font-size: 13px;
                font-weight: 600;
                padding: 8px 10px 8px 34px;
                box-shadow: 0 1px 1px rgba(15, 23, 42, 0.03);
            }

            .sales-order-search-input:focus {
                border-color: var(--accent);
                box-shadow: 0 0 0 3px var(--accent-soft);
                outline: none;
            }

            .sales-order-search-input::placeholder {
                color: var(--muted);
                font-weight: 500;
            }

            .sales-order-search-icon {
                position: absolute;
                top: 50%;
                left: 13px;
                width: 12px;
                height: 12px;
                border: 1.7px solid var(--muted);
                border-radius: 999px;
                transform: translateY(-58%);
                pointer-events: none;
            }

            .sales-order-search-icon::after {
                position: absolute;
                right: -5px;
                bottom: -4px;
                width: 6px;
                height: 1.7px;
                border-radius: 999px;
                background: var(--muted);
                content: "";
                transform: rotate(45deg);
                transform-origin: left center;
            }

            .date-custom-grid {
                display: grid;
                gap: 8px;
                min-width: 230px;
                padding-top: 4px;
            }

            .filter-chip-row {
                display: flex;
                flex-wrap: wrap;
                gap: 6px;
                margin: 0 0 12px;
            }

            .filter-chip {
                display: inline-flex;
                align-items: center;
                gap: 7px;
                border: 1px solid var(--line);
                border-radius: 999px;
                background: #fff;
                color: var(--ink);
                cursor: pointer;
                font-size: 12px;
                font-weight: 700;
                line-height: 1;
                padding: 6px 9px;
            }

            .filter-chip:hover {
                border-color: var(--accent);
                background: var(--accent-soft);
                color: var(--accent);
            }

            .filter-chip strong {
                color: var(--muted);
                font-size: 13px;
                line-height: 1;
            }

            .filter-chip-clear {
                border: none;
                background: transparent;
                color: var(--accent);
                cursor: pointer;
                font-size: 12px;
                font-weight: 700;
                padding: 6px 8px;
            }

            .sales-order-date-row {
                display: flex;
                flex-wrap: wrap;
                align-items: end;
                gap: 10px 16px;
                margin-bottom: 12px;
            }

            .date-range-options {
                display: flex;
                flex-wrap: wrap;
                gap: 8px 12px;
                border: 1px solid var(--line);
                border-radius: 6px;
                background: #fff;
                padding: 8px 10px;
            }

            .sales-order-page-actions {
                display: flex;
                justify-content: flex-end;
                align-items: center;
                gap: 8px;
                margin-bottom: 12px;
            }

            .sales-order-action-row {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 8px;
                border-top: 1px solid var(--line);
                border-bottom: 1px solid var(--line);
                margin-bottom: 12px;
                padding: 10px 0;
            }

            .selection-action-group {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 6px;
            }

            .selection-action-group > span {
                color: var(--muted);
                font-size: 11px;
                font-weight: 800;
                text-transform: uppercase;
            }

            .selection-action-divider {
                align-self: stretch;
                width: 1px;
                min-height: 28px;
                background: var(--line);
                margin: 0 2px;
            }

            .action-menu.small summary,
            .action-menu-disabled {
                min-height: 32px;
                padding: 6px 9px;
                font-size: 12px;
            }

            .action-menu-disabled {
                color: var(--muted);
                cursor: default;
                opacity: 0.65;
            }

            .action-menu-panel a,
            .action-menu-panel button {
                display: block;
                width: 100%;
                border: 0;
                border-radius: 5px;
                background: transparent;
                color: var(--ink);
                cursor: pointer;
                font: inherit;
                font-size: 12px;
                font-weight: 700;
                padding: 8px 9px;
                text-align: left;
                text-decoration: none;
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

            .so-note-cell span {
                display: block;
                max-width: 180px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .sku-form {
                display: grid;
                gap: 12px;
            }

            .form-panel {
                display: grid;
                gap: 14px;
                padding: 16px;
            }

            .form-subsection {
                display: grid;
                gap: 12px;
                border-top: 1px solid var(--line);
                padding-top: 14px;
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

            .form-grid.four {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }

            .form-grid.two-one {
                grid-template-columns: repeat(2, minmax(0, 1fr)) minmax(220px, 0.75fr);
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

            .checkbox-stack {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
            }

            .segmented-row label,
            .checkbox-stack label {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                border-radius: 6px;
                background: #fff;
                min-height: 38px;
                padding: 8px 10px;
                font-size: 13px;
                font-weight: 700;
            }

            .segmented-row label {
                border: 1px solid var(--line);
            }

            .segmented-row label {
                min-width: 178px;
                padding: 10px 12px;
            }

            .segmented-row input[type="radio"],
            .checkbox-stack input[type="checkbox"] {
                width: 16px;
                height: 16px;
                min-width: 16px;
                min-height: 16px;
                flex: 0 0 16px;
                accent-color: var(--accent);
            }

            .checkbox-stack {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 8px;
                align-self: end;
            }

            .checkbox-stack label {
                justify-content: flex-start;
                min-height: 42px;
                color: var(--ink);
                font-size: 13px;
            }

            .form-actions {
                margin-top: 2px;
                padding: 4px 0;
            }

            .form-actions-left {
                justify-content: flex-start;
            }

            .line-row {
                display: grid;
                grid-template-columns: 1fr 1fr 1fr auto;
                gap: 12px;
                align-items: end;
                margin-top: 12px;
            }

            .remove-line-btn {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 36px;
                height: 40px;
                border: 1px solid #fca5a5;
                border-radius: 6px;
                background: #fff;
                color: #ef4444;
                cursor: pointer;
                flex-shrink: 0;
                transition: background 0.1s, border-color 0.1s;
            }

            .remove-line-btn:hover {
                background: #fef2f2;
                border-color: #ef4444;
            }

            .remove-line-btn svg {
                width: 14px;
                height: 14px;
                flex-shrink: 0;
            }

            .remove-line-btn.invisible {
                visibility: hidden;
            }

            [data-flux-button].bg-white {
                border-color: var(--color-teal-600);
                color: var(--color-teal-600);
            }

            [data-flux-button].bg-white * {
                color: var(--color-teal-600);
            }

            [data-flux-button].bg-white:hover {
                background-color: color-mix(in oklab, var(--color-teal-600), transparent 93%);
            }

            [data-flux-button].text-\[var\(--color-accent-foreground\)\],
            [data-flux-button].text-\[var\(--color-accent-foreground\)\] * {
                color: var(--color-accent-foreground) !important;
            }

            [data-flux-button].text-white,
            [data-flux-button].text-white * {
                color: #fff !important;
            }

            [data-flux-field]:has([data-flux-control][required]) [data-flux-label]::after {
                content: ' *';
                color: #ef4444;
                font-weight: 700;
            }

            .type-grid { display: flex; flex-direction: column; gap: 6px; }
            .type-grid-header, .type-grid-row {
                display: grid;
                grid-template-columns: 72px 160px 1fr 1fr 1fr 1fr 40px;
                gap: 8px;
                align-items: center;
            }
            .type-grid-header {
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                color: var(--muted);
                padding: 0 0 4px;
                border-bottom: 1px solid var(--line);
            }
            .type-slug-cell {
                display: flex;
                align-items: center;
                height: 40px;
                padding: 0 12px;
                background: var(--surface);
                border: 1px solid var(--line);
                border-radius: 6px;
                font-size: 13px;
            }
            .type-slug-cell code {
                font-family: ui-monospace, monospace;
                font-size: 12px;
                color: var(--muted);
            }
            .type-grid-error {
                grid-column: 1 / -1;
            }

            .receive-line-panel {
                display: grid;
                gap: 14px;
                border-top: 1px solid var(--line);
                padding-top: 14px;
            }

            .form-error {
                margin: -4px 0 0;
                color: var(--danger);
                font-size: 12px;
                font-weight: 700;
            }

            /* Horizontal-scroll safety net: on narrow screens a wide table scrolls
               instead of overlapping or squishing its columns. */
            .table-scroll {
                overflow-x: auto;
            }

            /* Generic table style for index/detail tables. It avoids the fixed column
               model used by the movement ledger, so long codes wrap inside their cell
               instead of bleeding into the next column. */
            .data-table {
                table-layout: auto;
                width: 100%;
            }

            .data-table th,
            .data-table td {
                padding-left: 12px;
                padding-right: 12px;
                vertical-align: top;
            }

            .data-table th {
                white-space: nowrap;
            }

            .data-table td strong,
            .data-table td span,
            .data-table td small {
                overflow-wrap: anywhere;
            }

            /* The sales orders table sizes its columns to content (auto layout) instead
               of borrowing the inventory-movements fixed-width column model, which has no
               widths defined for these columns and let long platform order ids overflow
               into the neighbouring cell. */
            .sales-order-table {
                table-layout: fixed;
                min-width: 1320px;
            }

            .sales-order-table th:nth-child(1),
            .sales-order-table td:nth-child(1) {
                width: 52px;
                min-width: 52px;
                max-width: 52px;
            }

            .sales-order-table th:nth-child(2),
            .sales-order-table td:nth-child(2) {
                width: 160px;
                min-width: 150px;
            }

            .sales-order-table th:nth-child(3),
            .sales-order-table td:nth-child(3) {
                width: 280px;
                min-width: 240px;
            }

            .sales-order-table th:nth-child(4),
            .sales-order-table td:nth-child(4) {
                width: 170px;
                min-width: 150px;
            }

            .sales-order-table th:nth-child(5),
            .sales-order-table td:nth-child(5) {
                width: 230px;
                min-width: 200px;
            }

            .sales-order-table th:nth-child(6),
            .sales-order-table td:nth-child(6),
            .sales-order-table th:nth-child(7),
            .sales-order-table td:nth-child(7) {
                width: 170px;
                min-width: 150px;
            }

            .sales-order-table th:nth-child(8),
            .sales-order-table td:nth-child(8),
            .sales-order-table th:nth-child(9),
            .sales-order-table td:nth-child(9) {
                width: 120px;
                min-width: 110px;
            }

            .sales-order-table th:nth-child(10),
            .sales-order-table td:nth-child(10) {
                width: 180px;
            }

            .so-address-cell,
            .so-items-cell,
            .so-order-cell,
            .so-recipient-cell,
            .so-shop-cell {
                max-width: 240px;
            }

            .so-address-cell {
                max-width: 320px;
            }

            .so-address-cell strong,
            .so-address-cell span,
            .so-items-cell strong,
            .so-items-cell span,
            .so-order-cell strong,
            .so-order-cell span,
            .so-recipient-cell strong,
            .so-shop-cell strong {
                display: block;
                overflow-wrap: anywhere;
            }

            .so-item-line {
                margin-bottom: 6px;
            }

            .so-item-line:last-child {
                margin-bottom: 0;
            }

            .so-sku-line {
                display: flex;
                align-items: baseline;
                gap: 4px;
                white-space: nowrap;
            }

            .so-sku-line strong,
            .so-sku-line span {
                display: inline;
            }

            .so-sku-label {
                max-width: 100%;
                margin-top: 2px;
                overflow: hidden;
                overflow-wrap: normal !important;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .so-control-cell {
                min-width: 150px;
            }

            .so-select-cell {
                text-align: center;
            }

            .so-checkbox-hitbox {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 34px;
                height: 34px;
                border-radius: 6px;
                cursor: pointer;
            }

            .so-checkbox-hitbox:hover {
                background: var(--accent-soft);
            }

            .so-checkbox-hitbox input {
                width: 18px;
                height: 18px;
                cursor: pointer;
            }

            .so-checkbox-hitbox-header {
                width: 30px;
                height: 30px;
            }

            .table-control {
                width: 100%;
                min-width: 120px;
                border: 1px solid var(--line);
                border-radius: 6px;
                padding: 6px 8px;
                font: inherit;
                background: #fff;
            }

            .tracking-field {
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                gap: 4px;
                min-width: 170px;
            }

            .tracking-field .table-control {
                width: 100%;
            }

            .tracking-unsaved {
                display: inline-flex;
                width: fit-content;
                border-radius: 999px;
                background: var(--warning-soft);
                color: var(--warning);
                padding: 2px 6px;
                font-size: 11px;
                font-weight: 600;
                line-height: 1.2;
            }

            .status-stack {
                display: flex;
                flex-wrap: wrap;
                gap: 4px;
            }

            .danger-text {
                color: var(--danger);
            }

            .sales-order-detail-actions {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                flex-wrap: wrap;
            }

            .sales-order-detail-actions-main {
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
            }

            .sales-order-detail-actions-danger {
                margin-left: auto;
            }

            .sales-order-recipient-header {
                align-items: flex-start;
                justify-content: flex-start;
                flex-direction: column;
                gap: 12px;
            }

            @media (max-width: 720px) {
                .page {
                    width: min(100% - 20px, 1180px);
                    padding: 20px 0;
                }

                .page--wide {
                    width: min(100% - 20px, 1440px);
                }

                .page-header {
                    display: block;
                }

                .summary-grid,
                .table-toolbar,
                .movement-toolbar,
                .sales-order-filter-grid,
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
        <x-layout.navigation />

        <main class="page {{ ($pageWide ?? false) ? 'page--wide' : '' }}">
            @php
            $sectionNavLinks = match(true) {
                request()->routeIs('inventory.*', 'stock-adjustments.*') => [
                    ['label' => __('common.nav_inventory_overview'), 'href' => route('inventory.index'),           'active' => request()->routeIs('inventory.index')],
                    ['label' => __('common.nav_movements'),          'href' => route('inventory.movements.index'), 'active' => request()->routeIs('inventory.movements.index')],
                    ['label' => __('common.nav_stock_adjustment'),   'href' => route('stock-adjustments.create'),  'active' => request()->routeIs('stock-adjustments.*')],
                ],
                request()->routeIs('setup.*') => [
                    ['label' => __('common.nav_tenants'),        'href' => route('setup.tenants.index'),    'active' => request()->routeIs('setup.tenants.*')],
                    ['label' => __('common.nav_warehouses'),     'href' => route('setup.warehouses.index'), 'active' => request()->routeIs('setup.warehouses.*')],
                    ['label' => __('common.nav_shipping_methods'), 'href' => route('setup.shipping-methods.index'), 'active' => request()->routeIs('setup.shipping-methods.*')],
                    ['label' => __('common.nav_locations'),      'href' => route('setup.locations.index'),   'active' => request()->routeIs('setup.locations.*')],
                    ['label' => __('common.nav_packagings'),     'href' => route('setup.packagings.index'),  'active' => request()->routeIs('setup.packagings.*')],
                    ['label' => __('common.nav_other_settings'), 'href' => route('setup.other-settings'),   'active' => request()->routeIs('setup.other-settings')],
                ],
                default => [],
            };
            @endphp

            <header class="page-header">
                <div>
                    <h1>{{ $title ?? __('inventory.page_title') }}</h1>
                    <p>{{ $subtitle ?? __('inventory.page_subtitle') }}</p>
                </div>

                @if ($sectionNavLinks)
                    <nav class="section-nav">
                        @foreach ($sectionNavLinks as $link)
                            <a
                                href="{{ $link['href'] }}"
                                class="section-nav-link {{ $link['active'] ? 'is-active' : '' }}"
                                wire:navigate
                            >{{ $link['label'] }}</a>
                        @endforeach
                    </nav>
                @endif
            </header>

            {{ $slot }}
        </main>

        @livewireScripts
        @fluxScripts
    </body>
</html>
