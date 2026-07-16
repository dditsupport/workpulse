<?php
// =========================================================
// CSS Styles — extracted from original index.php
// =========================================================
function renderStyles(): void { ?>
<style>
:root{--bg:#1c1c24;--surface:#262632;--border:#363648;--accent:#1a8fe3;--text:#e6e6f0;--muted:#8c8ca0;--green:#27ae60;--yellow:#c9a800;--red:#dc4040;--blue:#1a8fe3;--purple:#9b59b6;--sidebar:220px}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',sans-serif;font-size:14px;background:var(--bg);color:var(--text);display:flex;min-height:100vh}
/* Sidebar */
.sidebar{width:var(--sidebar);background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;height:100vh;overflow-y:auto}
.sidebar-brand{padding:18px 16px;font-size:17px;font-weight:700;color:var(--accent);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px}
.sidebar-role{padding:8px 16px;font-size:11px;color:var(--muted);border-bottom:1px solid var(--border)}
.sidebar-nav{flex:1;padding:10px 8px}
.nav-item{display:flex;align-items:center;gap:8px;padding:9px 14px;border-radius:6px;color:var(--muted);text-decoration:none;margin-bottom:3px;transition:all .15s;font-size:13px}
.nav-item svg{flex-shrink:0}
.nav-item:hover,.nav-item.active{background:rgba(26,143,227,.12);color:var(--accent)}
.nav-sep{border:none;border-top:1px solid rgba(255,255,255,.08);margin:6px 14px}
/* Grouped nav (long lists) — collapsible group headings, ZipERP-style */
.sidebar-nav.is-grouped .nav-group{margin-bottom:2px}
.sidebar-nav.is-grouped .nav-group-head{display:flex;align-items:center;justify-content:space-between;width:100%;padding:9px 14px;border:none;background:transparent;color:var(--text);text-decoration:none;font-size:13px;font-weight:600;cursor:pointer;border-radius:6px;transition:background .12s,color .12s}
.sidebar-nav.is-grouped .nav-group-head:hover{background:rgba(255,255,255,.04)}
.sidebar-nav.is-grouped .nav-group-label{flex:1;text-align:left;letter-spacing:.01em}
.sidebar-nav.is-grouped .nav-group-chevron{display:flex;align-items:center;justify-content:center;color:var(--muted);transition:transform .18s ease}
.sidebar-nav.is-grouped .nav-group.open .nav-group-chevron{transform:rotate(180deg)}
.sidebar-nav.is-grouped .nav-group-items{display:none;padding:2px 0 6px 0}
.sidebar-nav.is-grouped .nav-group.open .nav-group-items{display:block}
.sidebar-nav.is-grouped .nav-group-items .nav-item{padding-left:22px;font-size:12.5px}
.sidebar-nav.is-grouped .nav-group-items hr.nav-sep{border-top-color:rgba(255,255,255,.18);margin:6px 22px}
.sidebar-emp{padding:10px 16px;font-size:12px;color:var(--muted);border-top:1px solid var(--border)}
.sidebar-footer{padding:14px;border-top:1px solid var(--border)}
.btn-logout{width:100%;background:transparent;border:1px solid var(--border);color:var(--muted);padding:8px;border-radius:6px;cursor:pointer;font-size:13px}
.btn-logout:hover{border-color:var(--red);color:var(--red)}
.sidebar-version{padding:8px 16px;font-size:10px;color:var(--muted);border-top:1px solid var(--border);line-height:1.6;opacity:.7}
/* Main */
.main{margin-left:var(--sidebar);flex:1;padding:26px 30px;max-width:1300px}
.page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
.page-header h2{font-size:19px;font-weight:600}
/* Stats */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(115px,1fr));gap:10px;margin-bottom:22px}
.stats-grid-sm{display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:8px}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:12px;text-align:center}
.stat-val{font-size:24px;font-weight:700}.stat-lbl{font-size:11px;color:var(--muted);margin-top:3px}
.stat-green .stat-val{color:var(--green)}.stat-yellow .stat-val{color:var(--yellow)}
.stat-blue .stat-val{color:var(--blue)}.stat-red .stat-val{color:var(--red)}.stat-purple .stat-val{color:var(--purple)}
/* Filter */
.filter-bar{display:flex;gap:8px;margin-bottom:12px;align-items:center;flex-wrap:wrap}
.flex-wrap{flex-wrap:wrap}
/* Table */
.table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:8px;overflow:auto;margin-top:8px}
.table{width:100%;border-collapse:collapse}
.table th{padding:11px 13px;text-align:left;font-size:11px;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border)}
.table td{padding:10px 13px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle}
.table tbody tr:hover{background:rgba(255,255,255,.02)}
.table tbody tr:last-child td{border-bottom:none}
.row-inactive{opacity:.5}
.empty-row{text-align:center;color:var(--muted);padding:32px !important;font-style:italic}
.table-count{margin-top:6px;font-size:12px;color:var(--muted)}
/* Badges */
.badge{display:inline-block;padding:3px 9px;border-radius:12px;font-size:11px;font-weight:600}
.badge-green{background:rgba(39,174,96,.2);color:#27ae60}.badge-yellow{background:rgba(201,168,0,.2);color:#c9a800}
.badge-blue{background:rgba(26,143,227,.2);color:#1a8fe3}.badge-red{background:rgba(220,64,64,.2);color:#dc4040}
.badge-amber{background:rgba(245,158,11,.2);color:#f59e0b}
.badge-purple{background:rgba(155,89,182,.2);color:#9b59b6}.badge-grey{background:rgba(140,140,160,.15);color:var(--muted)}
/* Actions */
.actions{white-space:nowrap;display:flex;gap:5px}.inline-form{display:inline}
/* Buttons */
.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:6px;font-size:13px;font-weight:500;cursor:pointer;border:none;text-decoration:none;transition:opacity .15s;white-space:nowrap}
.btn:hover{opacity:.85}
.btn-primary{background:var(--accent);color:#fff}.btn-secondary{background:var(--border);color:var(--text)}
.btn-success{background:rgba(39,174,96,.25);color:var(--green)}.btn-danger{background:rgba(220,64,64,.2);color:var(--red)}
.btn-danger-solid{background:var(--red);color:#fff}
.btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--border)}
.btn-sm{padding:4px 9px;font-size:12px}.w-full{width:100%;justify-content:center}.w-auto{width:auto}
/* Alerts */
.alert{padding:11px 15px;border-radius:6px;margin-bottom:18px;font-size:13px}
.alert-success{background:rgba(39,174,96,.1);color:var(--green);border:1px solid rgba(39,174,96,.25)}
.alert-error{background:rgba(220,64,64,.1);color:var(--red);border:1px solid rgba(220,64,64,.25)}
/* Forms */
.form-card{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:22px;max-width:800px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:4px}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group label{font-size:12px;color:var(--muted);font-weight:500}
.form-control{background:var(--bg);border:1px solid var(--border);color:var(--text);padding:7px 11px;border-radius:6px;font-size:13px;outline:none;width:100%}
.form-control:focus{border-color:var(--accent)}.form-control[readonly]{opacity:.6;cursor:default}
select.form-control{-webkit-appearance:menulist;appearance:menulist;background-color:#1c1c24;color:#e6e6f0}
select.form-control option{background:#1c1c24;color:#e6e6f0}
.checkbox-label{display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--text);padding-top:20px}
.required{color:var(--red)}.hint{font-size:11px;color:var(--muted);font-weight:400}
.form-section-title{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:18px 0 10px;border-bottom:1px solid var(--border);padding-bottom:5px}
.form-actions{margin-top:18px;display:flex;gap:10px}
.enrollment-info{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:4px}
.enroll-item{display:flex;align-items:center;gap:8px;background:var(--bg);padding:8px 14px;border-radius:6px;border:1px solid var(--border)}
.enroll-label{font-size:12px;color:var(--muted)}
code{font-family:Consolas,monospace;font-size:12px;background:rgba(255,255,255,.06);padding:2px 5px;border-radius:4px}
.text-muted{color:var(--muted);font-size:12px}
/* Login */
.login-body{display:flex;align-items:center;justify-content:center;min-height:100vh;min-height:100dvh;padding:16px;box-sizing:border-box}
.login-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:36px;width:380px;max-width:100%;box-sizing:border-box}
.login-logo{font-size:38px;text-align:center;color:var(--accent);margin-bottom:12px}
.login-title{text-align:center;font-size:19px;font-weight:700;margin-bottom:4px}
.login-sub{text-align:center;color:var(--muted);font-size:13px;margin-bottom:22px}
.login-hint{margin-top:18px;font-size:11px;color:var(--muted);text-align:center;line-height:1.7}
@media(max-width:900px){.stats-grid,.stats-grid-sm{grid-template-columns:repeat(3,1fr)}.form-grid{grid-template-columns:1fr}}
/* Report table */
.report-header-box{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:12px 18px;margin-bottom:12px;font-size:13px;line-height:1.8;color:var(--text)}
.rpt-table{font-size:13px}
.rpt-table thead th{font-size:11px;text-align:left;padding:9px 10px;vertical-align:bottom;white-space:nowrap;color:var(--muted);text-transform:uppercase}
.rpt-table td{vertical-align:middle;padding:8px 10px}
.rpt-sr{width:46px;text-align:center}
.rpt-emp-start > td{border-top:2px solid rgba(255,255,255,.18)}
.rpt-id{width:100px}
.rpt-name{min-width:150px}
.rpt-date{width:95px;white-space:nowrap;font-size:12px}
.rpt-hrs{width:105px;text-align:center;font-family:Consolas,monospace;font-size:12px;white-space:nowrap}
.rpt-loc-hdr{font-size:10px;color:var(--muted);font-weight:400;text-transform:none;letter-spacing:0}
.rpt-prompt{margin-top:24px;padding:32px;text-align:center;color:var(--muted);font-size:14px;background:var(--surface);border:1px solid var(--border);border-radius:8px}
/* Report filter bar */
.rpt-filter{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:nowrap}
.rpt-filter-emp{flex:1;min-width:200px;max-width:340px;font-size:14px}
.rpt-filter-month{width:130px;font-size:14px}
.rpt-filter-year{width:90px;font-size:14px}
.rpt-filter-chk{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--text);cursor:pointer;white-space:nowrap;user-select:none}
.rpt-filter-chk input{width:15px;height:15px;cursor:pointer}
/* Punch chips */
.rpt-punches-cell{padding:6px 10px !important}
.punch-chip{display:inline-flex;flex-direction:column;align-items:center;background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:4px 8px;margin:2px 3px 2px 0;vertical-align:top;min-width:72px}
.punch-chip-in{border-color:rgba(39,174,96,.4);background:rgba(39,174,96,.06)}
.punch-chip-out{border-color:rgba(26,143,227,.4);background:rgba(26,143,227,.06)}
.punch-chip-type{font-size:10px;font-weight:700;letter-spacing:.05em;line-height:1.2}
.punch-chip-in .punch-chip-type{color:var(--green)}
.punch-chip-out .punch-chip-type{color:var(--blue)}
.punch-chip-time{font-family:Consolas,monospace;font-size:12px;font-weight:600;color:var(--text);line-height:1.4}
.punch-chip-loc{font-size:10px;color:var(--muted);line-height:1.3;text-align:center;max-width:90px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
/* Settings */
.settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:4px}
.settings-full{grid-column:1/-1}
@media(max-width:700px){.settings-grid{grid-template-columns:1fr}}
/* ───────── Audit module ───────── */
.audit-table td,.audit-table th{font-size:12.5px;vertical-align:middle}
.audit-table .num{text-align:right;font-family:Consolas,monospace;white-space:nowrap}
.audit-cat-row{background:rgba(26,143,227,.06)}
.audit-cat-row td{font-weight:600}
.audit-cat-sum td{background:rgba(0,0,0,.12);font-size:11.5px}
.audit-total-sum td{background:rgba(0,0,0,.2);font-weight:600}
.audit-table input.form-control,.audit-table textarea.form-control,.audit-table select.form-control{font-size:12.5px;padding:5px 7px}
.approver-remark-banner{margin-top:6px;padding:6px 9px;background:rgba(220,64,64,.12);border-left:3px solid var(--red);color:#ff9a9a;font-size:11.5px;border-radius:0 4px 4px 0}
.sm-remark-banner{margin-top:6px;padding:6px 9px;background:rgba(26,143,227,.12);border-left:3px solid var(--blue);color:#9ed1f6;font-size:11.5px;border-radius:0 4px 4px 0}
.ops-remark-banner{margin-top:6px;padding:6px 9px;background:rgba(255,180,40,.12);border-left:3px solid var(--yellow);color:#ffce6b;font-size:11.5px;border-radius:0 4px 4px 0}
.mgmt-remark-banner{margin-top:6px;padding:6px 9px;background:rgba(155,89,182,.14);border-left:3px solid #9b59b6;color:#d3aef2;font-size:11.5px;border-radius:0 4px 4px 0}
/* Auditor's obtain-score traffic light. Red < 50, Orange 50–<75, Green ≥ 75.
   Applied to .param-obtain / .param-obtain-pct cells and to <span> wrappers
   in read-only views. Tinted background + bold colour so the score still
   pops on dark and on the muted "—" placeholder. */
.audit-score-red   {color:var(--red);    background:rgba(220,64,64,.18); font-weight:600}
.audit-score-orange{color:#ffb347;       background:rgba(255,150,40,.18); font-weight:600}
.audit-score-green {color:var(--green);  background:rgba(39,174,96,.18); font-weight:600}
/* Category-row bar tint — paints the whole "1. Cash & Bank Transactions"
   bar in the obtain colour. Overrides the default blue audit-cat-row
   background and the accent left border so the visual cue is unmissable. */
tr.audit-cat-row.cat-score-red   td{background:rgba(220,64,64,.20)!important}
tr.audit-cat-row.cat-score-red   {border-left-color:var(--red)!important;background:rgba(220,64,64,.20)!important}
tr.audit-cat-row.cat-score-orange td{background:rgba(255,150,40,.20)!important}
tr.audit-cat-row.cat-score-orange{border-left-color:#ffb347!important;background:rgba(255,150,40,.20)!important}
tr.audit-cat-row.cat-score-green td{background:rgba(39,174,96,.18)!important}
tr.audit-cat-row.cat-score-green {border-left-color:var(--green)!important;background:rgba(39,174,96,.18)!important}
.att-list{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:4px}
.att-chip{display:inline-flex;align-items:center;gap:4px;background:var(--bg);border:1px solid var(--border);border-radius:4px;padding:3px 7px;font-size:11px;color:var(--text);text-decoration:none;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.att-chip:hover{border-color:var(--accent);color:var(--accent)}
.att-annotate{display:inline-flex;align-items:center;gap:4px;background:rgba(26,143,227,.12);border:1px solid rgba(26,143,227,.45);border-radius:4px;padding:3px 7px;font-size:11px;color:#9ed1f6;text-decoration:none;white-space:nowrap}
.att-annotate:hover{background:rgba(26,143,227,.22);color:#cbe7ff;border-color:var(--accent)}
/* Annotated-attachment highlight states */
.att-chip.has-open-pins{background:rgba(220,64,64,.16);border-color:rgba(220,64,64,.55);color:#ff9a9a}
.att-chip.has-open-pins:hover{background:rgba(220,64,64,.26);color:#ffc4c4;border-color:var(--red)}
.att-chip.has-resolved-pins{background:rgba(39,174,96,.14);border-color:rgba(39,174,96,.45);color:#7be0a4}
.att-chip.has-resolved-pins:hover{background:rgba(39,174,96,.22);color:#a8eec0;border-color:var(--green)}
.att-annotate.has-open-pins{background:rgba(220,64,64,.18);border-color:rgba(220,64,64,.6);color:#ffb0b0}
.att-annotate.has-open-pins:hover{background:rgba(220,64,64,.28);color:#ffd2d2;border-color:var(--red)}
.att-annotate.has-resolved-pins{background:rgba(39,174,96,.16);border-color:rgba(39,174,96,.5);color:#7be0a4}
.att-annotate.has-resolved-pins:hover{background:rgba(39,174,96,.24);color:#a8eec0;border-color:var(--green)}
.att-pin-badge{display:inline-flex;align-items:center;justify-content:center;min-width:16px;height:16px;padding:0 4px;border-radius:9px;font-size:10px;font-weight:700;line-height:1;margin-left:2px}
.att-pin-badge-open{background:var(--red);color:#fff;box-shadow:0 0 0 1.5px rgba(255,255,255,.15)}
.att-pin-badge-done{background:var(--green);color:#0e1f17}

.btn-ghost-x{background:transparent;border:none;color:var(--muted);cursor:pointer;font-size:12px;padding:0 4px}
.btn-ghost-x:hover{color:var(--red)}
/* Searchable combobox (audit form pickers) */
.combo-wrap{position:relative}
.combo-input{padding-right:32px}
.combo-clear{display:none;position:absolute;top:50%;right:8px;transform:translateY(-50%);width:20px;height:20px;border:none;border-radius:50%;background:rgba(255,255,255,.08);color:var(--muted);font-size:14px;line-height:1;cursor:pointer;align-items:center;justify-content:center;padding:0}
.combo-clear:hover{background:rgba(220,64,64,.25);color:#dc4040}
.combo-wrap.has-value .combo-clear{display:flex}
/* Inline clear (×) button for plain filter inputs / selects.
   Mark up: <span class="input-clear-wrap"><input ...><button class="input-clear-btn" type="button">×</button></span>
   The shared init in renderShell() auto-toggles visibility and handles click. */
.input-clear-wrap{position:relative;display:inline-flex;align-items:stretch;flex:1 1 auto;min-width:0}
.input-clear-wrap > input,.input-clear-wrap > select{padding-right:30px;width:100%}
.input-clear-btn{display:none;position:absolute;top:50%;right:6px;transform:translateY(-50%);width:22px;height:22px;align-items:center;justify-content:center;border:none;border-radius:50%;background:rgba(255,255,255,.08);color:var(--muted);font-size:14px;line-height:1;cursor:pointer;padding:0}
.input-clear-btn:hover{background:rgba(220,64,64,.25);color:#dc4040}
.input-clear-wrap.has-value .input-clear-btn{display:flex}
.combo-dropdown{display:none;position:absolute;top:calc(100% + 2px);left:0;right:0;max-height:260px;overflow-y:auto;background:var(--surface);border:1px solid var(--border);border-radius:6px;box-shadow:0 8px 20px rgba(0,0,0,.35);z-index:30}
.combo-dropdown.open{display:block}
.combo-option{padding:8px 12px;font-size:14px;color:var(--text);cursor:pointer;border-bottom:1px solid rgba(255,255,255,.04)}
.combo-option:last-child{border-bottom:none}
.combo-option:hover,.combo-option.active{background:var(--accent);color:#fff}
.combo-option.empty{color:var(--muted);font-style:italic;cursor:default;background:transparent}
.combo-option.empty:hover{background:transparent;color:var(--muted)}
/* ───────── Mobile responsive pass ───────── */
/* Toggle button: visible on both desktop & mobile. On desktop it
   collapses the sidebar (body.sidebar-collapsed); on mobile it opens
   the drawer (body.sidebar-open). */
.nav-toggle{display:inline-flex;margin-bottom:12px;background:var(--surface);border:1px solid var(--border);color:var(--text);border-radius:6px;padding:8px 12px;font-size:14px;line-height:1;cursor:pointer;align-items:center;gap:6px}
.sidebar-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99}
/* Desktop sidebar collapse: slides off-screen, main expands to fill. */
@media(min-width:769px){
  .sidebar{transition:transform .2s ease}
  .main{transition:margin-left .2s ease,max-width .2s ease}
  body.sidebar-collapsed .sidebar{transform:translateX(-100%)}
  body.sidebar-collapsed .main{margin-left:0;max-width:100%}
}
@media(max-width:900px){.stats-grid,.stats-grid-sm{grid-template-columns:repeat(3,1fr)}.form-grid{grid-template-columns:1fr}}
@media(max-width:768px){
  body{display:block}
  .sidebar{transform:translateX(-100%);transition:transform .2s ease;z-index:100;width:260px}
  body.sidebar-open .sidebar{transform:translateX(0)}
  body.sidebar-open .sidebar-backdrop{display:block}
  .main{margin-left:0;padding:14px;max-width:100%}
  .page-header{flex-direction:column;align-items:flex-start;gap:10px}
  .page-header h2{font-size:17px}
  .stats-grid,.stats-grid-sm{grid-template-columns:repeat(2,1fr)}
  .form-grid,.settings-grid{grid-template-columns:1fr !important}
  .form-card{padding:16px;max-width:100%}
  .filter-bar,.rpt-filter{flex-wrap:wrap}
  .rpt-filter-emp,.rpt-filter-month,.rpt-filter-year{width:100%;max-width:100%}
  /* Card-stack any table marked data-stack */
  .table-wrap[data-stack] table,
  .table-wrap[data-stack] thead,
  .table-wrap[data-stack] tbody,
  .table-wrap[data-stack] tr,
  .table-wrap[data-stack] td{display:block;width:100%}
  .table-wrap[data-stack] thead{display:none}
  .table-wrap[data-stack] tr{border-bottom:1px solid var(--border);padding:10px 4px}
  .table-wrap[data-stack] td{padding:6px 12px;border:none;display:flex;justify-content:space-between;gap:10px;align-items:center}
  .table-wrap[data-stack] td::before{content:attr(data-label);font-size:11px;text-transform:uppercase;color:var(--muted);font-weight:600;flex-shrink:0;letter-spacing:.04em}
  .table-wrap[data-stack] td.actions{justify-content:flex-end;flex-wrap:wrap}
  /* Employee-boundary divider (attendance report pivot) */
  .table-wrap[data-stack] tr.rpt-emp-start{border-top:3px solid var(--accent);margin-top:14px;padding-top:14px;box-shadow:0 -1px 0 rgba(26,143,227,.3)}
  /* Audit table — specialized mobile card layout */
  .table-wrap[data-stack] table.audit-table tr{padding:10px 8px;border-bottom:1px solid var(--border)}
  .table-wrap[data-stack] table.audit-table tr.audit-cat-row{background:rgba(26,143,227,.10);border-left:4px solid var(--accent);padding:14px 10px;margin-top:12px}
  .table-wrap[data-stack] table.audit-table tr.audit-param-row{background:var(--surface);margin-top:2px}
  .table-wrap[data-stack] table.audit-table tr.audit-cat-sum{background:rgba(0,0,0,.12);font-size:11.5px;padding:6px 10px}
  .table-wrap[data-stack] table.audit-table tr.audit-total-sum{background:rgba(0,0,0,.22);font-size:12px;padding:8px 10px;font-weight:600}
  .table-wrap[data-stack] table.audit-table tr.audit-cat-sum td,
  .table-wrap[data-stack] table.audit-table tr.audit-total-sum td{display:inline-block;width:auto;text-align:left !important;padding:2px 6px}
  .table-wrap[data-stack] table.audit-table tr.audit-cat-sum td::before,
  .table-wrap[data-stack] table.audit-table tr.audit-total-sum td::before{display:none}
  .table-wrap[data-stack] table.audit-table td{padding:4px 8px;font-size:13px;min-height:0}
  .table-wrap[data-stack] table.audit-table td:empty{display:none}
  .table-wrap[data-stack] table.audit-table td.wide-cell{display:block}
  .table-wrap[data-stack] table.audit-table td.wide-cell::before{display:block;margin-bottom:4px}
  .table-wrap[data-stack] table.audit-table td.param-text{display:block;font-size:14px;font-weight:600;color:var(--text);padding:2px 8px 8px;line-height:1.4}
  .table-wrap[data-stack] table.audit-table td.param-text::before{display:none}
  .table-wrap[data-stack] table.audit-table td.cat-name{display:block;font-size:15px;font-weight:700;color:var(--accent);padding:2px 8px}
  .table-wrap[data-stack] table.audit-table td.cat-name::before{display:none}
  .table-wrap[data-stack] table.audit-table td.srno::before{display:none}
  .table-wrap[data-stack] table.audit-table td.srno{display:none}
  .table-wrap[data-stack] table.audit-table textarea.form-control,
  .table-wrap[data-stack] table.audit-table input[type=file]{width:100%}
  .btn{padding:10px 14px;font-size:14px}
  .btn-sm{padding:7px 10px;font-size:13px}
  .form-control{font-size:16px;padding:10px 12px}
  input[type=file]{max-width:100%}
  .login-card{width:100%;max-width:380px;padding:26px}
  .login-logo{font-size:34px}
  .login-title{font-size:18px}
  /* Outlet Directory: let Address breathe on phones (desktop kept narrow). */
  .od-address-col,.od-address-cell{max-width:none !important;min-width:240px}
  /* Checklist: stack the answer below the question on mobile so the
     control gets full row width instead of fighting the question text. */
  .table-wrap table.chk-table,
  .table-wrap table.chk-table thead,
  .table-wrap table.chk-table tbody,
  .table-wrap table.chk-table tr,
  .table-wrap table.chk-table td{display:block;width:100%}
  .table-wrap table.chk-table thead{display:none}
  .table-wrap table.chk-table tr{border-bottom:1px solid var(--border);padding:6px 0}
  .table-wrap table.chk-table td{border:none;padding:6px 12px}
  .table-wrap table.chk-table td.chk-num{display:none}
  .table-wrap table.chk-table td.chk-particular{font-size:14px;font-weight:500;color:var(--text);padding:10px 12px 4px;line-height:1.45}
  .table-wrap table.chk-table td.chk-answer{padding:4px 12px 12px}
  .table-wrap table.chk-table td.chk-answer .form-control,
  .table-wrap table.chk-table td.chk-answer select{width:100% !important;max-width:none}
  .table-wrap table.chk-table td.chk-section{padding:8px 13px;background:var(--border);font-weight:700;font-size:12px}
}
@media(max-width:480px){
  .stats-grid,.stats-grid-sm{grid-template-columns:1fr}
  .actions{flex-wrap:wrap}
  .login-card{padding:22px 18px;border-radius:10px}
  .login-sub{margin-bottom:16px}
}
</style>
<?php }
