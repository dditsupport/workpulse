<?php
// =========================================================
// Price Tags — printable 882 × 1314 pt sheet (3 × 8 tags).
// Items are sourced from product_shelf_life:
//   name  ← item_name
//   basic ← basic
//   desc  ← description
// Only products with a non-null `basic` are included.
// Gate: isSuperadmin() || hasTxn('price_tags')
// =========================================================

// ── AJAX: save item field back to product_shelf_life ─────
function doSaveTagItem(): void {
    if (!isSuperadmin() && !hasTxn('price_tags')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Access denied']);
        exit;
    }
    $id      = (int)($_POST['id'] ?? 0);
    $name    = trim($_POST['item_name'] ?? '');
    $basic   = trim($_POST['basic']     ?? '');
    $tax     = trim($_POST['tax']       ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid id']); exit;
    }
    $basicVal = ($basic !== '' && is_numeric($basic)) ? (float)$basic : null;
    $taxVal   = ($tax   !== '' && is_numeric($tax))   ? (float)$tax   : null;

    $st = getDb()->prepare(
        'UPDATE product_shelf_life SET item_name=?, basic=?, tax=?, description=? WHERE id=?'
    );
    $st->execute([$name ?: null, $basicVal, $taxVal, $description !== '' ? $description : null, $id]);

    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

// ── Page: iframe host inside the app shell ───────────────
function pagePriceTags(): void {
    if (!isSuperadmin() && !hasTxn('price_tags')) { echo '<div class="alert alert-error">Access denied.</div>'; return; }
?>
<div class="page-header" style="display:flex;align-items:center;gap:12px">
    <h2 style="margin:0">Price Tags</h2>
    <span style="font-size:12px;color:var(--muted)">882 × 1314 pt · 3 × 8 = 24 tags / sheet · Basic + Tax</span>
</div>
<iframe src="?page=price_tags_app" title="Price Tags"
        style="width:100%;height:calc(100vh - 140px);min-height:640px;border:1px solid var(--border,#e2e8f0);border-radius:10px;background:#eeeae3"></iframe>
<?php
}

// ── Page: standalone price-tag app (full document) ──────
// Rendered via early-return in index.php so no app shell is emitted.
function pagePriceTagsApp(): void {
    if (!isSuperadmin() && !hasTxn('price_tags')) {
        http_response_code(403); echo 'Access denied.'; return;
    }

    // Pull items straight from product_shelf_life. Only rows with a numeric
    // basic price are usable as price tags.
    $rows = getDb()
        ->query("SELECT id, item_name, basic, tax, description
                 FROM product_shelf_life
                 WHERE basic IS NOT NULL
                 ORDER BY item_group, item_name")
        ->fetchAll();

    $items = array_map(fn($r) => [
        'id'    => (int)$r['id'],
        'name'  => (string)$r['item_name'],
        'basic' => (float)$r['basic'],
        'tax'   => isset($r['tax']) && $r['tax'] !== null ? (float)$r['tax'] : null,
        'desc'  => (string)($r['description'] ?? ''),
    ], $rows);

    $itemsJson = json_encode(
        $items,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Cake Price Tags · 882 × 1314 pt</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root{
    --paper:#eeeae3;
    --ink:#1a1612;
    --muted:#6b635a;
    --rule:#d8d2c8;
    --cream:#f6ecd3;
  }
  *{box-sizing:border-box}
  html,body{margin:0;padding:0;background:var(--paper);color:var(--ink);font-family:Inter,system-ui,sans-serif;-webkit-font-smoothing:antialiased}

  .chrome{position:sticky;top:0;z-index:50;background:rgba(238,234,227,.88);backdrop-filter:saturate(140%) blur(10px);border-bottom:1px solid var(--rule)}
  .chrome-inner{max-width:1500px;margin:0 auto;padding:14px 24px;display:flex;align-items:center;gap:20px;flex-wrap:wrap}
  .brand{display:flex;align-items:baseline;gap:10px}
  .brand .mark{font-family:Inter,sans-serif;font-weight:700;font-size:18px;letter-spacing:-.01em}
  .brand .sub{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.14em}
  .chrome-actions{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap}
  .btn{font-family:Inter,sans-serif;font-size:12px;font-weight:500;padding:8px 14px;border-radius:7px;border:1px solid var(--rule);background:#fbf8f2;color:var(--ink);cursor:pointer;letter-spacing:.02em;transition:background .15s,border-color .15s,transform .08s}
  .btn:hover{background:#fff;border-color:#bfb7ab}
  .btn:active{transform:translateY(1px)}
  .btn.primary{background:var(--ink);color:var(--cream);border-color:var(--ink)}
  .btn.primary:hover{background:#2a241d;color:#fff}
  .btn .kbd{margin-left:8px;font-family:'JetBrains Mono',monospace;font-size:10px;opacity:.55}

  .workspace{max-width:1500px;margin:0 auto;padding:28px 24px 80px;display:grid;grid-template-columns:390px 1fr;gap:16px}

  .panel{background:#fbf8f2;border:1px solid var(--rule);border-radius:12px;padding:18px;align-self:start;position:sticky;top:84px;max-height:calc(100vh - 110px);overflow:auto}
  .panel h3{margin:0 0 4px;font-family:Inter,sans-serif;font-weight:700;font-size:16px}
  .panel .hint{font-family:'JetBrains Mono',monospace;font-size:10.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:16px}
  .field{margin:14px 0}
  .field label{display:block;font-size:11px;font-weight:500;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:6px}
  .seg{display:grid;grid-auto-flow:column;grid-auto-columns:1fr;background:#eeeae3;border:1px solid var(--rule);border-radius:8px;padding:3px;gap:3px}
  .seg button{font-family:Inter,sans-serif;font-size:12px;padding:7px 6px;border:none;border-radius:5px;background:transparent;cursor:pointer;color:var(--ink)}
  .seg button.on{background:var(--ink);color:var(--cream)}
  .swatches{display:flex;gap:8px;flex-wrap:wrap}
  .swatch{width:34px;height:34px;border-radius:50%;cursor:pointer;border:2px solid transparent;box-shadow:inset 0 0 0 1px rgba(0,0,0,.1)}
  .swatch.on{border-color:var(--ink)}
  .toggle{display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-top:1px solid var(--rule)}
  .toggle:first-of-type{border-top:none}
  .toggle span{font-size:13px}
  .tg{width:36px;height:20px;border-radius:999px;background:#d8d2c8;border:none;cursor:pointer;position:relative;transition:background .15s}
  .tg::after{content:"";position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.15);transition:transform .15s}
  .tg.on{background:var(--ink)}
  .tg.on::after{transform:translateX(16px)}
  .num-input{width:100%;font:inherit;padding:7px 10px;border:1px solid var(--rule);border-radius:6px;background:#fff}

  .sheet-shell{display:flex;flex-direction:column;align-items:flex-start;gap:14px}
  .sheet-meta{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);display:flex;gap:14px;letter-spacing:.06em}
  .sheet-meta b{color:var(--ink);font-weight:500}

  /* ============================================================
     SHEET: 882 × 1314 pt. 1pt = 1/72in.
     Tag: 85 × 55 mm  = 240.94 × 155.91 pt (≈ 241 × 156 pt).
     Layout: 3 cols × 8 rows = 24 tags/sheet.
     ============================================================ */
  .sheet{
    width:882pt;height:1314pt;background:#fff;
    box-shadow:0 1px 2px rgba(0,0,0,.04),0 12px 40px -8px rgba(0,0,0,.12);
    position:relative;
    padding:33pt 79.5pt;
    display:grid;
    grid-template-columns:repeat(3, 241pt);
    grid-template-rows:repeat(8, 156pt);
    column-gap:0;
    row-gap:0;
    justify-content:start;align-content:start;
    --scale:.6;
    transform:scale(var(--scale));transform-origin:top left;
    margin-bottom:calc((1 - var(--scale)) * -1314pt);
    margin-right:calc((1 - var(--scale)) * -882pt);
  }
  .sheet::before,.sheet::after{
    content:"882 × 1314 pt · 12.25″ × 18.25″";position:absolute;top:8pt;left:0;right:0;
    text-align:center;font-family:'JetBrains Mono',monospace;font-size:9pt;color:#b8b0a4;letter-spacing:.2em;
  }
  .sheet::after{top:auto;bottom:6pt;content:"TAG · 85 × 55 mm · 3 × 8 = 24 per sheet"}

  /* =============== TAG =============== */
  .tag{
    width:241pt;height:156pt;
    position:relative;overflow:hidden;
    background:var(--card-bg);
    color:var(--cream);
    font-family:Inter,sans-serif;
    border-radius:0;
  }
  .tag.v-cream{background:#f3ead6;color:#2a1f18;--tgold:#8a6a1f}
  .tag.v-mocha{background:#2a1f18;color:#f6ecd3;--tgold:#e6b64a}
  .tag.v-cocoa{background:#3a2820;color:#f6ecd3;--tgold:#e6b64a}
  .tag.v-ink{background:#17130f;color:#f6ecd3;--tgold:#e6b64a}
  .tag.v-ivory{background:#f7f2e8;color:#17130f;--tgold:#8a6a1f;border:.7pt solid #e4dcc9}

  .tag .allergen-col{
    position:absolute;left:0;top:0;bottom:0;width:42pt;
    display:flex;align-items:center;justify-content:center;
    pointer-events:none;
  }
  .tag .allergen{
    transform:rotate(-90deg);
    transform-origin:center center;
    width:140pt;
    text-align:center;
    font-family:Inter,sans-serif;
    font-size:4.8pt;line-height:1.3;
    color:var(--tgold);
    font-weight:500;
    letter-spacing:.01em;
    white-space:nowrap;
  }
  .tag .allergen br + *,
  .tag .allergen{display:block}

  .veg{
    position:absolute;right:15pt;top:30pt;
    width:11pt;height:11pt;border:1pt solid #1f7a1f;background:#fff;
    display:flex;align-items:center;justify-content:center;
  }
  .veg::after{content:"";width:5.5pt;height:5.5pt;border-radius:50%;background:#1f7a1f}
  .veg.nonveg{border-color:#a31818}
  .veg.nonveg::after{background:#a31818;border-radius:0}

  .body{
    position:absolute;inset:0;
    padding:12pt 18pt 12pt 48pt;
    display:flex;flex-direction:column;
    align-items:center;justify-content:center;
    text-align:center;
    gap:4pt;
  }
  .tag:not(.has-allergen) .body{padding-left:18pt}

  .name{
    font-family:Inter,sans-serif;
    font-weight:800;
    font-size:17pt;line-height:1.04;
    letter-spacing:.01em;
    color:var(--tgold);
    text-wrap:balance;
    max-width:180pt;
  }
  .name.long{font-size:15pt}
  .name.verylong{font-size:13pt}
  .name.extreme{font-size:11pt}

  .price{
    font-family:Inter,sans-serif;
    font-weight:700;
    color:var(--tgold);
    line-height:1;
    display:inline-flex;align-items:baseline;gap:2pt;
    margin-top:3pt;
  }
  .price .rs{font-size:16pt;font-weight:700;margin-right:1pt}
  .price .int{font-size:22pt;font-weight:800;letter-spacing:-.01em}
  .price .dot{font-size:10pt;font-weight:800;letter-spacing:-.02em;margin:0 -.5pt}
  .price .dec{font-size:11pt;font-weight:700;opacity:.95;margin-left:.5pt}
  .price .plus{font-size:10pt;font-weight:500;opacity:.85;margin:0 2pt}
  .price .tax{font-size:9pt;font-weight:500;letter-spacing:.04em;opacity:.85}
  .price .slash{font-size:12pt;font-weight:600;opacity:.85;margin-left:1pt}

  .desc{
    font-family:Inter,sans-serif;
    font-size:7.4pt;line-height:1.3;
    color:var(--tgold);
    font-weight:400;
    max-width:180pt;
    text-wrap:pretty;
    overflow:hidden;
    display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;
    margin-top:2pt;
  }
  .desc.long{font-size:6.8pt;-webkit-line-clamp:5}

  .cutlines{position:absolute;inset:0;pointer-events:none;z-index:2;width:882pt;height:1314pt;}

  /* Only one sheet rendered on screen at a time; printAll() stacks them. */

  /* Sheet pager */
  .seg button:disabled{opacity:.3;cursor:default}
  #seg-sheet{display:flex;flex-wrap:wrap;align-items:center;gap:3px;background:#eeeae3;border:1px solid var(--rule);border-radius:8px;padding:3px}
  #seg-sheet button{font-family:Inter,sans-serif;font-size:12px;padding:7px 9px;min-width:28px;flex:0 0 auto;border:none;border-radius:5px;background:transparent;cursor:pointer;color:var(--ink);transition:background .12s}
  #seg-sheet button.on{background:var(--ink);color:var(--cream)}
  #seg-sheet .pager-ell{padding:0 4px;color:var(--muted);font-size:12px;user-select:none}

  @media print{
    @page{size:882pt 1314pt;margin:0}
    html,body{background:#fff}
    .chrome,.panel,.sheet-meta,.drawer{display:none !important}
    .workspace{display:block;padding:0;margin:0;max-width:none}
    .sheet-shell{gap:0;padding:0;margin:0}
    .sheet{box-shadow:none;transform:none !important;margin:0 !important;page-break-after:always;break-after:page}
    .sheet:last-child{page-break-after:auto;break-after:auto}
    .sheet::before,.sheet::after{display:none}
    *{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  }

  .drawer{position:fixed;inset:auto 0 0 0;max-height:70vh;background:#fbf8f2;border-top:1px solid var(--rule);box-shadow:0 -12px 40px rgba(0,0,0,.1);transform:translateY(100%);transition:transform .25s ease;z-index:60;display:flex;flex-direction:column}
  .drawer.open{transform:translateY(0)}
  .drawer-head{padding:14px 24px;border-bottom:1px solid var(--rule);display:flex;align-items:center;gap:12px}
  .drawer-head h3{margin:0;font-weight:700;font-size:16px}
  .drawer-head .hint{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);letter-spacing:.08em}
  .drawer-body{overflow:auto;padding:14px 24px 24px}
  table.items{width:100%;border-collapse:collapse;font-size:12.5px}
  table.items th{text-align:left;padding:8px 10px;border-bottom:1px solid var(--rule);font-weight:500;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);position:sticky;top:0;background:#fbf8f2}
  table.items td{padding:6px 10px;border-bottom:1px solid #ece6d9;vertical-align:top}
  table.items td input,table.items td textarea,table.items td select{width:100%;border:1px solid transparent;background:transparent;padding:4px 6px;font:inherit;color:inherit;border-radius:4px;resize:vertical}
  table.items td input:hover,table.items td textarea:hover,table.items td select:hover{border-color:var(--rule)}
  table.items td input:focus,table.items td textarea:focus,table.items td select:focus{outline:none;border-color:var(--ink);background:#fff}
  table.items td.num input{text-align:right;font-family:'JetBrains Mono',monospace}
  table.items td.use{text-align:center;width:40px}
  .row-del{color:#c8c0b2;cursor:pointer;padding:0 4px;background:none;border:none}
</style>
</head>
<body>

<header class="chrome">
  <div class="chrome-inner">
    <div class="brand">
      <span class="mark">Price Tags</span>
      <span class="sub">882 × 1314 pt · 3 × 8 · Basic + Tax</span>
    </div>
    <div class="chrome-actions">
      <button class="btn" id="btn-edit">Edit items <span class="kbd">E</span></button>
      <button class="btn primary" id="btn-print">Print / PDF <span class="kbd">⌘P</span></button>
    </div>
  </div>
</header>

<main class="workspace">

  <aside class="panel">
    <h3>Design</h3>
    <div class="hint">Tag styling</div>

    <div class="field" style="display:none">
      <label>Color</label>
      <div class="swatches" id="swatches">
        <div class="swatch on" data-v="cocoa" style="background:#3a2820" title="Cocoa"></div>
      </div>
    </div>

    <div class="field">
      <label>Elements</label>
      <div class="toggle"><span>Allergen strip (left)</span><button class="tg on" data-k="showAllergen"></button></div>
      <div class="toggle"><span>Description</span><button class="tg on" data-k="showDesc"></button></div>
      <div class="toggle"><span>Show + Tax</span><button class="tg on" data-k="showTax"></button></div>
      <div class="toggle"><span>Cutting lines</span><button class="tg on" data-k="showCutLines"></button></div>
      <div class="toggle"><span>Duplicate per page (24×)</span><button class="tg" data-k="duplicatePerPage"></button></div>
    </div>

    <div class="field">
      <label>Sheet</label>
      <div class="seg" id="seg-sheet"></div>
    </div>

    <div class="field">
      <label>Allergen text</label>
      <textarea id="allergen-text" rows="3" style="width:100%;font:inherit;padding:8px;border:1px solid var(--rule);border-radius:6px;background:#fff;resize:vertical"></textarea>
    </div>
  </aside>

  <section class="sheet-shell">
    <div class="sheet-meta">
      <span>Sheet <b id="m-sheet">1 of 1</b></span>
      <span>·</span>
      <span><b id="m-count">0</b> tags</span>
      <span>·</span>
      <span>Tag <b>85 × 55 mm</b> · page <b>882 × 1314 pt</b></span>
    </div>
    <div id="sheets-wrap"></div>
  </section>

</main>

<div class="drawer" id="drawer">
  <div class="drawer-head">
    <h3>Items</h3>
    <span class="hint">Name · Basic · Tax · Ingredients</span>
    <div style="margin-left:auto;display:flex;gap:8px">
      <button class="btn" id="btn-close-drawer">Close</button>
    </div>
  </div>
  <div class="drawer-body">
    <table class="items" id="items-table">
      <thead>
        <tr>
          <th class="use"><label style="display:inline-flex;align-items:center;gap:5px;cursor:pointer;font:inherit"><input type="checkbox" id="check-all" title="Select / unselect all"> Use</label></th>
          <th>Item name</th>
          <th style="width:110px">Basic (Rs.)</th>
          <th style="width:90px">Tax (%)</th>
          <th>Ingredients / description</th>
          <th style="width:22px"></th>
        </tr>
      </thead>
      <tbody id="items-body"></tbody>
    </table>
  </div>
</div>

<script>
const DEFAULT_ITEMS = <?= $itemsJson ?>;

const DEFAULT_ALLERGEN = "*Allergen Info — Contains Refined Wheat Flour, Milk Solids.";

const TWEAKS = {
  "color": "cocoa",
  "showVeg": true,
  "showAllergen": true,
  "showDesc": true,
  "showTax": true,
  "sheet": 1,
  "showCutLines": true,
  "duplicatePerPage": false,
  "allergen": "*Allergen Info - Contains Refined Wheat Flour, Milk Solids."
};

const DEFAULT_TAX = 5;
const state = {
  ...TWEAKS,
  items: DEFAULT_ITEMS.map(i => ({...i, tax: i.tax != null ? i.tax : DEFAULT_TAX, enabled:true})),
};

function nameClass(name){
  const n = name.length;
  if(n > 36) return "name extreme";
  if(n > 28) return "name verylong";
  if(n > 20) return "name long";
  return "name";
}

function splitBasic(v){
  const n = Number(v);
  if(!isFinite(n)) return {int:"—", dec:""};
  const int = Math.floor(n);
  const cents = Math.round((n - int) * 100);
  return {int:String(int), dec: cents ? String(cents).padStart(2,'0') : ""};
}

function tagHtml(item){
  const {int, dec} = splitBasic(item.basic);
  const rate = item.tax != null ? Number(item.tax) : 0;
  const rateStr = rate % 1 === 0 ? String(rate) : String(rate).replace(/\.?0+$/, '');
  const taxHtml = state.showTax
    ? `<span class="slash">/-</span><span class="plus">+</span><span class="tax">${rateStr}% Tax</span>`
    : `<span class="slash">/-</span>`;

  let allergenText = state.allergen || "";
  if(allergenText.indexOf('\n') === -1){
    allergenText = allergenText.replace(/\s+Wheat/i, '\nWheat');
  }
  const allergen = state.showAllergen
    ? `<div class="allergen-col"><div class="allergen">${allergenText.replace(/\n/g,'<br>')}</div></div>`
    : "";
  const desc = state.showDesc && item.desc
    ? `<div class="desc${item.desc.length > 120 ? ' long':''}">${item.desc}</div>`
    : "";
  const veg = `<div class="veg" aria-label="Vegetarian"></div>`;

  return `
    <div class="tag v-${state.color}${state.showAllergen?' has-allergen':''}">
      ${allergen}
      ${veg}
      <div class="body">
        <div class="${nameClass(item.name)}">${item.name}</div>
        <div class="price">
          <span class="rs">Rs.</span><span class="int">${int}</span>${dec?`<span class="dot">.</span><span class="dec">${dec}</span>`:''}${taxHtml}
        </div>
        ${desc}
      </div>
    </div>
  `;
}

function cutLinesHtml(){
  if(!state.showCutLines) return '';
  const PL=79.5, PT=33, W=241, H=156, SW=882, SH=1314, ROWS=8, COLS=3;
  const xs = [PL, PL+W, PL+2*W, PL+3*W];
  const ys = [];
  for(let r=0; r<=ROWS; r++) ys.push(PT + r*H);

  const stroke = 'stroke="#9a9a9a" stroke-width=".5" stroke-dasharray="4 3" shape-rendering="crispEdges"';
  const solid  = 'stroke="#9a9a9a" stroke-width=".5" shape-rendering="crispEdges"';
  const TICK = 12;

  const vLines = xs.map(x => `<line x1="${x}" y1="0" x2="${x}" y2="${SH}" ${stroke}/>`).join('');
  const hLines = ys.map(y => `<line x1="0" y1="${y}" x2="${SW}" y2="${y}" ${stroke}/>`).join('');
  const xTicks = xs.map(x =>
    `<line x1="${x}" y1="${PT-TICK-2}" x2="${x}" y2="${PT-2}" ${solid}/>` +
    `<line x1="${x}" y1="${PT+ROWS*H+2}" x2="${x}" y2="${PT+ROWS*H+2+TICK}" ${solid}/>`
  ).join('');
  const yTicks = ys.map(y =>
    `<line x1="${PL-TICK-2}" y1="${y}" x2="${PL-2}" y2="${y}" ${solid}/>` +
    `<line x1="${PL+COLS*W+2}" y1="${y}" x2="${PL+COLS*W+2+TICK}" y2="${y}" ${solid}/>`
  ).join('');
  return `<svg class="cutlines" viewBox="0 0 ${SW} ${SH}" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none">${vLines}${hLines}${xTicks}${yTicks}</svg>`;
}

function buildSheetHtml(slice){
  return slice.map(tagHtml).join('') + cutLinesHtml();
}

function sheetSlices(){
  const enabled = state.items.filter(i => i.enabled);
  const perSheet = 24;
  if(state.duplicatePerPage){
    // One sheet per item: each sheet contains 24 copies of that item.
    return enabled.map(it => Array(perSheet).fill(it));
  }
  const sheets = Math.max(1, Math.ceil(enabled.length / perSheet));
  const out = [];
  for(let i=0;i<sheets;i++) out.push(enabled.slice(i*perSheet, i*perSheet + perSheet));
  return out;
}

function render(){
  const allSlices = sheetSlices();
  const sheets = Math.max(1, allSlices.length);
  if(state.sheet > sheets) state.sheet = sheets;
  if(state.sheet < 1)      state.sheet = 1;
  const idx = state.sheet - 1;
  const slice = allSlices[idx] || [];

  const wrap = document.getElementById('sheets-wrap');

  if(allSlices.length === 0){
    wrap.innerHTML = `<div class="sheet"><div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;text-align:center;padding:40pt;color:#8a8278;font-size:14pt;font-family:Inter,sans-serif">
      <div>
        <div style="font-weight:700;font-size:18pt;color:#4a4238;margin-bottom:12pt">No products to show</div>
        <div style="line-height:1.5;max-width:420pt">Price tags are drawn from <b>Shelf Life</b> products that have a <b>Basic</b> price set. Open Shelf Life, edit a product, and fill in the Basic field.</div>
      </div>
    </div></div>`;
  } else {
    wrap.innerHTML = `<div class="sheet" id="sheet">${buildSheetHtml(slice)}</div>`;
  }

  document.getElementById('m-sheet').textContent = `${state.sheet} of ${sheets}`;
  document.getElementById('m-count').textContent = `${slice.length}`;

  renderPager(state.sheet, sheets);

  document.querySelectorAll('.swatch').forEach(s => s.classList.toggle('on', s.dataset.v === state.color));
  document.querySelectorAll('.tg').forEach(t => t.classList.toggle('on', !!state[t.dataset.k]));
  document.getElementById('allergen-text').value = state.allergen;
}

function renderPager(current, total){
  const seg = document.getElementById('seg-sheet');
  const go = n => { state.sheet = Math.max(1, Math.min(total, n)); persist(); render(); };
  const btn = (label, page, opts = {}) => {
    const b = document.createElement('button');
    b.textContent = label;
    if(opts.disabled) b.disabled = true;
    if(opts.active)   b.classList.add('on');
    if(!opts.disabled) b.onclick = () => go(page);
    return b;
  };
  seg.innerHTML = '';

  // Show first, last, current ± 1, with ellipses.
  const pages = [];
  const add = n => { if(!pages.includes(n)) pages.push(n); };
  add(1);
  for(let i = current-1; i <= current+1; i++) if(i >= 1 && i <= total) add(i);
  add(total);
  pages.sort((a,b) => a-b);

  seg.appendChild(btn('«', 1,         {disabled: current === 1}));
  seg.appendChild(btn('‹', current-1, {disabled: current === 1}));

  let prev = 0;
  for(const n of pages){
    if(n - prev > 1){
      const ell = document.createElement('span');
      ell.className = 'pager-ell'; ell.textContent = '…';
      seg.appendChild(ell);
    }
    seg.appendChild(btn(String(n), n, {active: n === current}));
    prev = n;
  }

  seg.appendChild(btn('›', current+1, {disabled: current === total}));
  seg.appendChild(btn('»', total,     {disabled: current === total}));
}

function persist(){
  const out = {
    color: state.color, showVeg: state.showVeg, showAllergen: state.showAllergen,
    showDesc: state.showDesc, showTax: state.showTax,
    sheet: state.sheet, allergen: state.allergen, showCutLines: state.showCutLines,
    duplicatePerPage: state.duplicatePerPage,
  };
  try{
    localStorage.setItem('pricetags.state.v4', JSON.stringify(out));
    localStorage.removeItem('pricetags.items.v4');
    localStorage.removeItem('pricetags.state.v3');
    localStorage.removeItem('pricetags.items.v3');
    localStorage.removeItem('pricetags.state.v2');
    localStorage.removeItem('pricetags.items.v2');
    localStorage.removeItem('pricetags.items');
    localStorage.removeItem('pricetags.state');
  }catch(e){}
}
function restore(){
  try{
    const s = JSON.parse(localStorage.getItem('pricetags.state.v4') || 'null');
    if(s) Object.assign(state, s);
  }catch(e){}
  if(!state.allergen) state.allergen = DEFAULT_ALLERGEN;
}

document.getElementById('swatches').addEventListener('click', e => {
  const s = e.target.closest('.swatch'); if(!s) return;
  state.color = s.dataset.v; persist(); render();
});
document.querySelectorAll('.tg').forEach(t => {
  t.addEventListener('click', () => { state[t.dataset.k] = !state[t.dataset.k]; persist(); render(); });
});
document.getElementById('allergen-text').addEventListener('input', e => {
  state.allergen = e.target.value; persist(); render();
});

document.getElementById('btn-print').onclick = () => printAll();

function printAll(){
  const allSlices = sheetSlices();
  const wrap = document.getElementById('sheets-wrap');
  const singleHtml = wrap.innerHTML;

  // Render every sheet stacked; browser paginates on .sheet boundaries.
  wrap.innerHTML = allSlices.map(slice => `<div class="sheet">${buildSheetHtml(slice)}</div>`).join('');

  const restore = () => {
    wrap.innerHTML = singleHtml;
    render();
    window.removeEventListener('afterprint', restore);
  };
  window.addEventListener('afterprint', restore);
  setTimeout(() => window.print(), 80);
}

const drawer = document.getElementById('drawer');
document.getElementById('btn-edit').onclick = () => { drawer.classList.toggle('open'); if(drawer.classList.contains('open')) renderTable(); };
document.getElementById('btn-close-drawer').onclick = () => drawer.classList.remove('open');

// Debounced save to DB — fires 700ms after last keystroke on a field.
const _saveTimers = {};
function saveItemToDb(i){
  clearTimeout(_saveTimers[i]);
  _saveTimers[i] = setTimeout(() => {
    const it = state.items[i];
    if(!it || !it.id) return;
    const fd = new FormData();
    fd.append('action',    'price_tag_save_item');
    fd.append('id',        it.id);
    fd.append('item_name', it.name   ?? '');
    fd.append('basic',     it.basic  != null ? it.basic  : '');
    fd.append('tax',       it.tax    != null ? it.tax    : '');
    fd.append('description', it.desc ?? '');
    const row = document.querySelector(`#items-body tr[data-i="${i}"]`);
    const ind = row?.querySelector('.save-ind');
    if(ind){ ind.textContent = '💾'; ind.style.color = '#aaa'; }
    fetch('index.php', {method:'POST', body:fd})
      .then(r => r.json())
      .then(d => { if(ind) { ind.textContent = d.ok ? '✓' : '✗'; ind.style.color = d.ok ? '#4a9' : '#c44'; } })
      .catch(() => { if(ind){ ind.textContent = '✗'; ind.style.color = '#c44'; } });
  }, 700);
}

function renderTable(){
  const body = document.getElementById('items-body');
  body.innerHTML = state.items.map((it, i) => `
    <tr data-i="${i}">
      <td class="use"><input type="checkbox" ${it.enabled?'checked':''} data-k="enabled"></td>
      <td><input value="${(it.name||'').replace(/"/g,'&quot;')}" data-k="name"></td>
      <td class="num"><input type="number" step="0.01" value="${it.basic ?? ''}" data-k="basic"></td>
      <td class="num"><input type="number" step="0.01" min="0" max="100" placeholder="—" value="${it.tax != null ? it.tax : ''}" data-k="tax"></td>
      <td><textarea rows="2" data-k="desc">${(it.desc||'').replace(/</g,'&lt;')}</textarea></td>
      <td style="text-align:center;font-size:11px;color:#aaa;width:22px" class="save-ind"></td>
    </tr>
  `).join('');

  body.querySelectorAll('input,textarea').forEach(el => {
    el.addEventListener('input', e => {
      const tr = e.target.closest('tr');
      const i = +tr.dataset.i;
      const k = e.target.dataset.k;
      let v = e.target.type === 'checkbox' ? e.target.checked :
              e.target.type === 'number' ? (e.target.value === '' ? null : Number(e.target.value)) :
              e.target.value;
      state.items[i][k] = v;
      persist(); render();
      if(k === 'enabled') syncCheckAll(); else saveItemToDb(i);
    });
  });
  syncCheckAll();
}

// Reflect the collective "Use" state on the header master checkbox:
// checked when all on, unchecked when none, indeterminate when mixed.
function syncCheckAll(){
  const master = document.getElementById('check-all');
  if(!master) return;
  const total = state.items.length;
  const on = state.items.filter(it => it.enabled).length;
  master.checked = total > 0 && on === total;
  master.indeterminate = on > 0 && on < total;
}

document.getElementById('check-all').addEventListener('change', e => {
  const on = e.target.checked;
  state.items.forEach(it => { it.enabled = on; });
  persist(); render(); renderTable();
});

document.addEventListener('keydown', e => {
  if((e.key === 'e' || e.key === 'E') && !e.metaKey && !e.ctrlKey && !e.target.matches('input,textarea')){
    e.preventDefault(); document.getElementById('btn-edit').click();
  }
});

restore();
render();
</script>
</body>
</html>
<?php
}
