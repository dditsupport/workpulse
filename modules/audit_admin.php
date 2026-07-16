<?php
// =========================================================
// Audit Module — admin masters (templates / categories / parameters)
// and their POST handlers. Gated by auditCanAdmin() (txn_audit_admin).
// Loaded by modules/audit.php.
// =========================================================

// ===========================================================
// PAGE: Audit Templates (admin)
// Unified tree view: template list at top, tree of categories +
// parameters below for the selected template. Add Category /
// Add Parameter happens inline via modals.
// ===========================================================
function pageAuditTemplates(): void {
    if (!auditCanAdmin()) { echo '<p>Access denied.</p>'; return; }
    $templates = auditGetTemplates(false);
    $selId = (int)($_GET['template_id'] ?? 0);
    $sel = null;
    if ($selId > 0) {
        foreach ($templates as $t) {
            if ((int)$t['id'] === $selId) { $sel = $t; break; }
        }
    }
    if (!$sel && $templates) { $sel = $templates[0]; $selId = (int)$sel['id']; }

    // Build the tree for the selected template
    $cats = $selId > 0 ? auditGetCategories($selId) : [];
    $catIds = array_column($cats, 'id');
    $params = [];
    if ($catIds) {
        $ph = implode(',', array_fill(0, count($catIds), '?'));
        $st = getDb()->prepare("SELECT * FROM audit_parameters WHERE category_id IN ({$ph}) ORDER BY category_id, sort_order, id");
        $st->execute($catIds);
        $params = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $paramsByCat = [];
    foreach ($params as $p) { $paramsByCat[(int)$p['category_id']][] = $p; }
    ?>
    <div class="page-header">
        <h2>Audit Template</h2>
        <div class="actions">
            <a class="btn btn-ghost"
               href="?page=export_audit_templates<?= $selId > 0 ? '&template_id=' . (int)$selId : '' ?>"
               target="_blank"
               title="<?= $selId > 0 ? 'Export the selected template as CSV' : 'Export all templates as CSV' ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                Export CSV<?= $selId > 0 ? ' (this template)' : ' (all)' ?>
            </a>
            <button type="button" class="btn btn-primary" onclick="auditTplOpen('new', null)">+ New Template</button>
        </div>
    </div>

    <?php if (count($templates) > 1): ?>
    <form method="GET" class="filter-bar" style="margin-bottom:14px">
        <input type="hidden" name="page" value="audit_templates">
        <select name="template_id" class="form-control" style="max-width:360px" onchange="this.form.submit()">
            <?php foreach ($templates as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= $selId === (int)$t['id'] ? 'selected' : '' ?>>
                    <?= h($t['name']) ?><?= $t['is_active'] ? '' : ' (inactive)' ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>

    <?php if (!$sel): ?>
        <div class="empty-card" style="padding:30px;text-align:center;color:var(--muted)">
            No templates yet. Click <strong>+ New Template</strong> to create one.
        </div>
    <?php else: ?>
        <?php
        $catSum  = 0.0;
        foreach ($cats as $c) { $catSum += (float)$c['weightage']; }
        ?>
        <div class="form-card" style="margin-bottom:14px">
            <form method="POST" id="tplNameForm" class="tpl-name-row">
                <input type="hidden" name="action" value="save_audit_template">
                <input type="hidden" name="id" value="<?= (int)$sel['id'] ?>">
                <input type="hidden" name="is_active" value="<?= (int)$sel['is_active'] ?>">
                <label class="tpl-name-label">Name <span class="required">*</span></label>
                <input class="form-control" name="name" id="tpl-name-input" required value="<?= h($sel['name']) ?>" data-original="<?= h($sel['name']) ?>">
                <div class="tpl-name-actions">
                    <button type="submit" class="btn btn-primary" id="tplSaveBtn" disabled>Save</button>
                    <button type="button" class="btn btn-ghost" onclick="auditTplCancelName()">Cancel</button>
                </div>
            </form>
            <div class="tpl-toolbar">
                <div class="tpl-toolbar-left">
                    <button type="button" class="btn btn-primary" onclick="auditCatOpen('new', null)">Add Category</button>
                    <button type="button" class="btn btn-primary" onclick="auditPrmOpen('new', null)">Add Parameter</button>
                </div>
                <div class="tpl-toolbar-right">
                    <button type="button" class="btn btn-secondary" onclick="auditTplOpen('edit', <?= (int)$sel['id'] ?>)">
                        <span aria-hidden="true">✎</span> Edit
                    </button>
                    <form method="POST" class="inline-form" onsubmit="return confirm('Delete template &quot;<?= h(addslashes($sel['name'])) ?>&quot; and ALL its categories/parameters?')">
                        <input type="hidden" name="action" value="del_audit_template">
                        <input type="hidden" name="id" value="<?= (int)$sel['id'] ?>">
                        <button class="btn btn-danger" type="submit"><span aria-hidden="true">🗑</span> Delete</button>
                    </form>
                </div>
            </div>
            <div class="tpl-meta">
                <span class="tpl-meta-pill <?= $sel['is_active'] ? 'on' : 'off' ?>"><?= $sel['is_active'] ? 'Active' : 'Inactive' ?></span>
                <span class="tpl-meta-pill"><?= count($cats) ?> categor<?= count($cats) === 1 ? 'y' : 'ies' ?></span>
                <span class="tpl-meta-pill"><?= count($params) ?> parameter<?= count($params) === 1 ? '' : 's' ?></span>
                <span class="tpl-meta-pill <?= abs($catSum - 100) < 0.05 ? 'good' : 'warn' ?>">Category Wt sum: <?= number_format($catSum, 2) ?> / 100</span>
            </div>
        </div>

        <div class="audit-tree">
            <div class="audit-tree-head">
                <div>Name</div>
                <div class="num">Weightage</div>
                <div class="actions-col">Actions</div>
            </div>
            <?php if (!$cats): ?>
                <div class="audit-tree-empty">No categories yet. Click <strong>Add Category</strong> to start.</div>
            <?php else: foreach ($cats as $c):
                $list = $paramsByCat[(int)$c['id']] ?? [];
                $pSum = 0.0; foreach ($list as $pp) $pSum += (float)$pp['score_weightage'];
            ?>
                <div class="audit-tree-cat">
                    <div class="audit-tree-row cat-row" data-cat-id="<?= (int)$c['id'] ?>">
                        <div class="name">
                            <button type="button" class="caret" onclick="auditTreeToggle(this)" aria-expanded="true">▾</button>
                            <span class="folder" aria-hidden="true">📁</span>
                            <span class="lbl"><?= h($c['name']) ?></span>
                        </div>
                        <div class="num"><?= number_format((float)$c['weightage'], 0) ?></div>
                        <div class="actions-col">
                            <button type="button" class="btn btn-sm btn-secondary" onclick="auditCatOpen('edit', <?= (int)$c['id'] ?>)">Edit</button>
                            <form method="POST" class="inline-form" onsubmit="return confirm('Delete category &quot;<?= h(addslashes($c['name'])) ?>&quot; and ALL its parameters?')">
                                <input type="hidden" name="action" value="del_audit_category">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                            </form>
                        </div>
                    </div>
                    <div class="audit-tree-children">
                        <?php if (!$list): ?>
                            <div class="audit-tree-row prm-row empty">
                                <div class="name"><span class="diamond" aria-hidden="true">◇</span><em>No parameters</em></div>
                                <div class="num">—</div>
                                <div class="actions-col"></div>
                            </div>
                        <?php else: foreach ($list as $p): ?>
                            <div class="audit-tree-row prm-row" data-prm-id="<?= (int)$p['id'] ?>">
                                <div class="name">
                                    <span class="diamond" aria-hidden="true">◆</span>
                                    <span class="lbl"><?= h($p['parameter_text']) ?></span>
                                    <?php if (!$p['is_active']): ?><span class="prm-pill off">Inactive</span><?php endif; ?>
                                    <?php if ($p['type'] !== 'rating'): ?>
                                        <span class="prm-pill type"><?= h($p['type']) ?><?= $p['type'] === 'value' && $p['max_value'] !== null ? ' · max ' . h($p['max_value']) : '' ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="num"><?= number_format((float)$p['score_weightage'], 0) ?></div>
                                <div class="actions-col">
                                    <button type="button" class="btn btn-sm btn-secondary" onclick='auditPrmOpen("edit", <?= (int)$p['id'] ?>)'>Edit</button>
                                    <form method="POST" class="inline-form" onsubmit="return confirm('Delete this parameter?')">
                                        <input type="hidden" name="action" value="del_audit_parameter">
                                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                        <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>
                        <div class="audit-tree-row prm-sum">
                            <div class="name" style="text-align:right;color:var(--muted);font-size:11.5px;text-transform:uppercase;letter-spacing:.04em">Param Wt sum within category:</div>
                            <div class="num <?= abs($pSum - 100) < 0.05 ? 'good' : 'warn' ?>"><?= number_format($pSum, 2) ?> / 100</div>
                            <div class="actions-col"></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    <?php endif; ?>

    <?php auditTplPageStyles(); ?>
    <?php auditTplPageModals($templates, $cats); ?>
    <?php auditTplPageJs($templates, $cats); ?>
    <?php
}

// ── CSS for the tree page ──
// All colors derived from the global dark-theme tokens defined in
// styles.php (--bg, --surface, --border, --text, --muted, --accent…) so
// this page picks up the rest of the app's look automatically.
function auditTplPageStyles(): void {
    ?>
    <style>
    .tpl-name-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;color:var(--text)}
    .tpl-name-row .tpl-name-label{font-weight:600;font-size:13px;min-width:60px;color:var(--text)}
    .tpl-name-row input[type=text],.tpl-name-row input.form-control{flex:1;min-width:240px;max-width:520px}
    .tpl-name-actions{display:flex;gap:8px}
    .tpl-toolbar{display:flex;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-top:14px}
    .tpl-toolbar-left,.tpl-toolbar-right{display:flex;gap:8px;flex-wrap:wrap}
    .tpl-meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;font-size:11.5px}
    .tpl-meta-pill{background:rgba(255,255,255,.06);color:var(--muted);padding:4px 10px;border-radius:999px;font-weight:500}
    .tpl-meta-pill.on{background:rgba(39,174,96,.18);color:var(--green)}
    .tpl-meta-pill.off{background:rgba(255,255,255,.06);color:var(--muted)}
    .tpl-meta-pill.good{background:rgba(39,174,96,.18);color:var(--green)}
    .tpl-meta-pill.warn{background:rgba(220,64,64,.18);color:var(--red)}

    .audit-tree{border:1px solid var(--border);border-radius:10px;overflow:hidden;background:var(--surface);color:var(--text)}
    .audit-tree-head{display:grid;grid-template-columns:1fr 110px 220px;gap:6px;background:rgba(255,255,255,.04);padding:10px 14px;font-size:11.5px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);border-bottom:1px solid var(--border)}
    .audit-tree-head .num{text-align:right}
    .audit-tree-head .actions-col{text-align:right}
    .audit-tree-empty{padding:30px;text-align:center;color:var(--muted)}
    .audit-tree-cat{border-bottom:1px solid var(--border)}
    .audit-tree-cat:last-child{border-bottom:none}
    .audit-tree-row{display:grid;grid-template-columns:1fr 110px 220px;gap:6px;align-items:center;padding:9px 14px;color:var(--text)}
    .audit-tree-row .name{display:flex;align-items:center;gap:8px;min-width:0;color:var(--text)}
    .audit-tree-row .name .lbl{overflow:hidden;text-overflow:ellipsis;color:var(--text)}
    .audit-tree-row .num{text-align:right;font-variant-numeric:tabular-nums;font-size:13px;color:var(--text)}
    .audit-tree-row .num.good{color:var(--green);font-weight:600}
    .audit-tree-row .num.warn{color:var(--red);font-weight:600}
    .audit-tree-row .actions-col{display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap}
    .audit-tree-row .caret{appearance:none;border:none;background:transparent;cursor:pointer;font-size:12px;color:var(--muted);width:18px;padding:0;line-height:1}
    .audit-tree-row .caret[aria-expanded="false"]{transform:rotate(-90deg)}
    .audit-tree-row .folder{font-size:14px}
    .audit-tree-row .diamond{font-size:11px;color:var(--yellow)}
    .cat-row{background:rgba(255,255,255,.03);font-weight:600}
    .cat-row .name .lbl{color:var(--text)}
    .prm-row{padding-left:46px;font-size:13px}
    .prm-row.empty{color:var(--muted);font-style:italic}
    .prm-sum{padding-left:14px;background:rgba(255,255,255,.02);font-size:11.5px;color:var(--muted)}
    .prm-sum .name{color:var(--muted)}
    .prm-pill{background:rgba(255,255,255,.06);color:var(--muted);padding:1px 8px;border-radius:999px;font-size:11px;font-weight:500;margin-left:6px}
    .prm-pill.off{background:rgba(255,255,255,.06);color:var(--muted)}
    .prm-pill.type{background:rgba(26,143,227,.18);color:var(--accent)}
    .audit-tree-children.collapsed{display:none}

    /* Modals — dark surface, light text to match the rest of the app */
    .audit-tpl-overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);display:none;z-index:9000;align-items:flex-start;justify-content:center;padding:40px 16px;overflow:auto}
    .audit-tpl-overlay.open{display:flex}
    .audit-tpl-modal{background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:10px;width:100%;max-width:560px;box-shadow:0 16px 48px rgba(0,0,0,.6)}
    .audit-tpl-modal h3{margin:0;padding:14px 18px;border-bottom:1px solid var(--border);font-size:15px;color:var(--text)}
    .audit-tpl-modal .body{padding:14px 18px;display:grid;gap:12px}
    .audit-tpl-modal .foot{padding:12px 18px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px}
    .audit-tpl-modal label{font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}

    @media (max-width: 720px){
        .audit-tree-head,.audit-tree-row{grid-template-columns:1fr 70px 150px;gap:4px;padding:9px 10px}
        .prm-row{padding-left:28px}
    }
    </style>
    <?php
}

// ── Modals: New Template / Edit Template / Add+Edit Category / Add+Edit Parameter ──
function auditTplPageModals(array $templates, array $cats): void {
    ?>
    <!-- Template create/edit -->
    <div class="audit-tpl-overlay" id="tplModal" role="dialog" aria-modal="true">
        <form class="audit-tpl-modal" method="POST">
            <input type="hidden" name="action" value="save_audit_template">
            <input type="hidden" name="id" id="tpl-modal-id">
            <h3 id="tpl-modal-title">New Template</h3>
            <div class="body">
                <div>
                    <label>Name</label>
                    <input type="text" class="form-control" name="name" id="tpl-modal-name" required>
                </div>
                <div>
                    <label class="checkbox-label" style="text-transform:none;letter-spacing:0">
                        <input type="checkbox" name="is_active" id="tpl-modal-active" value="1" checked> Active
                    </label>
                </div>
            </div>
            <div class="foot">
                <button type="button" class="btn btn-ghost" onclick="auditModalClose('tplModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Template</button>
            </div>
        </form>
    </div>

    <!-- Category create/edit -->
    <div class="audit-tpl-overlay" id="catModal" role="dialog" aria-modal="true">
        <form class="audit-tpl-modal" method="POST">
            <input type="hidden" name="action" value="save_audit_category">
            <input type="hidden" name="id" id="cat-modal-id">
            <input type="hidden" name="template_id" id="cat-modal-tpl">
            <h3 id="cat-modal-title">Add Category</h3>
            <div class="body">
                <div>
                    <label>Name</label>
                    <input type="text" class="form-control" name="name" id="cat-modal-name" required>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                    <div>
                        <label>Weightage</label>
                        <input type="number" step="0.01" class="form-control" name="weightage" id="cat-modal-wt" required>
                    </div>
                    <div>
                        <label>Sort Order</label>
                        <input type="number" class="form-control" name="sort_order" id="cat-modal-sort" value="0">
                    </div>
                </div>
            </div>
            <div class="foot">
                <button type="button" class="btn btn-ghost" onclick="auditModalClose('catModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Category</button>
            </div>
        </form>
    </div>

    <!-- Parameter create/edit -->
    <div class="audit-tpl-overlay" id="prmModal" role="dialog" aria-modal="true">
        <form class="audit-tpl-modal" method="POST">
            <input type="hidden" name="action" value="save_audit_parameter">
            <input type="hidden" name="id" id="prm-modal-id">
            <h3 id="prm-modal-title">Add Parameter</h3>
            <div class="body">
                <div>
                    <label>Category</label>
                    <select class="form-control" name="category_id" id="prm-modal-cat" required>
                        <option value="">— Select —</option>
                        <?php foreach ($cats as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Parameter Text</label>
                    <textarea class="form-control" name="parameter_text" id="prm-modal-text" rows="2" required></textarea>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                    <div>
                        <label>Type</label>
                        <select class="form-control" name="type" id="prm-modal-type" onchange="auditPrmToggleMax()">
                            <option value="rating">Rating (0–5)</option>
                            <option value="value">Value (numeric)</option>
                            <option value="boolean">Boolean (Yes/No)</option>
                        </select>
                    </div>
                    <div id="prm-modal-max-wrap" style="display:none">
                        <label>Max Value</label>
                        <input type="number" step="0.01" class="form-control" name="max_value" id="prm-modal-max">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
                    <div>
                        <label>Score Weightage</label>
                        <input type="number" step="0.01" class="form-control" name="score_weightage" id="prm-modal-wt" required>
                    </div>
                    <div>
                        <label>Sort Order</label>
                        <input type="number" class="form-control" name="sort_order" id="prm-modal-sort" value="0">
                    </div>
                    <div>
                        <label>Active</label>
                        <label class="checkbox-label" style="text-transform:none;letter-spacing:0;font-weight:500">
                            <input type="checkbox" name="is_active" id="prm-modal-active" value="1" checked> Active
                        </label>
                    </div>
                </div>
            </div>
            <div class="foot">
                <button type="button" class="btn btn-ghost" onclick="auditModalClose('prmModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Parameter</button>
            </div>
        </form>
    </div>
    <?php
}

// ── JS for modals + tree toggling + name dirty-check ──
function auditTplPageJs(array $templates, array $cats): void {
    // Pre-load lookup maps so the Edit modals can populate without a round-trip
    $tplMap = [];
    foreach ($templates as $t) { $tplMap[(int)$t['id']] = ['name' => $t['name'], 'is_active' => (int)$t['is_active']]; }

    $catMap = [];
    foreach ($cats as $c) {
        $catMap[(int)$c['id']] = [
            'template_id' => (int)$c['template_id'],
            'name'        => $c['name'],
            'weightage'   => (float)$c['weightage'],
            'sort_order'  => (int)$c['sort_order'],
        ];
    }

    // Parameter map for Edit (re-fetch for the current template)
    $prmMap = [];
    if ($cats) {
        $catIds = array_column($cats, 'id');
        $ph = implode(',', array_fill(0, count($catIds), '?'));
        $st = getDb()->prepare("SELECT * FROM audit_parameters WHERE category_id IN ({$ph})");
        $st->execute($catIds);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $prmMap[(int)$p['id']] = [
                'category_id'     => (int)$p['category_id'],
                'parameter_text'  => $p['parameter_text'],
                'type'            => $p['type'],
                'max_value'       => $p['max_value'] !== null ? (float)$p['max_value'] : null,
                'score_weightage' => (float)$p['score_weightage'],
                'sort_order'      => (int)$p['sort_order'],
                'is_active'       => (int)$p['is_active'],
            ];
        }
    }

    $selId = (int)($_GET['template_id'] ?? 0);
    if (!$selId && $templates) $selId = (int)$templates[0]['id'];
    ?>
    <script>
    (function(){
        var tplMap = <?= json_encode($tplMap, JSON_UNESCAPED_UNICODE) ?>;
        var catMap = <?= json_encode($catMap, JSON_UNESCAPED_UNICODE) ?>;
        var prmMap = <?= json_encode($prmMap, JSON_UNESCAPED_UNICODE) ?>;
        var selTplId = <?= (int)$selId ?>;

        window.auditModalOpen  = function(id){
            var el = document.getElementById(id); if (!el) return;
            el.classList.add('open'); document.body.style.overflow='hidden';
        };
        window.auditModalClose = function(id){
            var el = document.getElementById(id); if (!el) return;
            el.classList.remove('open'); document.body.style.overflow='';
        };
        document.querySelectorAll('.audit-tpl-overlay').forEach(function(o){
            o.addEventListener('click', function(e){ if (e.target === o) auditModalClose(o.id); });
        });
        document.addEventListener('keydown', function(e){
            if (e.key !== 'Escape') return;
            document.querySelectorAll('.audit-tpl-overlay.open').forEach(function(o){ auditModalClose(o.id); });
        });

        // Template modal
        window.auditTplOpen = function(mode, id){
            document.getElementById('tpl-modal-id').value = '';
            document.getElementById('tpl-modal-name').value = '';
            document.getElementById('tpl-modal-active').checked = true;
            if (mode === 'edit' && id && tplMap[id]) {
                document.getElementById('tpl-modal-title').textContent = 'Edit Template';
                document.getElementById('tpl-modal-id').value = id;
                document.getElementById('tpl-modal-name').value = tplMap[id].name || '';
                document.getElementById('tpl-modal-active').checked = !!tplMap[id].is_active;
            } else {
                document.getElementById('tpl-modal-title').textContent = 'New Template';
            }
            auditModalOpen('tplModal');
            setTimeout(function(){ document.getElementById('tpl-modal-name').focus(); }, 50);
        };

        // Category modal
        window.auditCatOpen = function(mode, id){
            document.getElementById('cat-modal-id').value = '';
            document.getElementById('cat-modal-tpl').value = selTplId;
            document.getElementById('cat-modal-name').value = '';
            document.getElementById('cat-modal-wt').value = '';
            document.getElementById('cat-modal-sort').value = '0';
            if (mode === 'edit' && id && catMap[id]) {
                document.getElementById('cat-modal-title').textContent = 'Edit Category';
                document.getElementById('cat-modal-id').value = id;
                document.getElementById('cat-modal-tpl').value = catMap[id].template_id;
                document.getElementById('cat-modal-name').value = catMap[id].name || '';
                document.getElementById('cat-modal-wt').value = catMap[id].weightage;
                document.getElementById('cat-modal-sort').value = catMap[id].sort_order;
            } else {
                document.getElementById('cat-modal-title').textContent = 'Add Category';
            }
            auditModalOpen('catModal');
            setTimeout(function(){ document.getElementById('cat-modal-name').focus(); }, 50);
        };

        // Parameter modal
        window.auditPrmToggleMax = function(){
            var t = document.getElementById('prm-modal-type').value;
            document.getElementById('prm-modal-max-wrap').style.display = (t === 'value') ? '' : 'none';
        };
        window.auditPrmOpen = function(mode, id){
            document.getElementById('prm-modal-id').value = '';
            document.getElementById('prm-modal-cat').value = '';
            document.getElementById('prm-modal-text').value = '';
            document.getElementById('prm-modal-type').value = 'rating';
            document.getElementById('prm-modal-max').value = '';
            document.getElementById('prm-modal-wt').value = '';
            document.getElementById('prm-modal-sort').value = '0';
            document.getElementById('prm-modal-active').checked = true;
            if (mode === 'edit' && id && prmMap[id]) {
                document.getElementById('prm-modal-title').textContent = 'Edit Parameter';
                document.getElementById('prm-modal-id').value = id;
                document.getElementById('prm-modal-cat').value = prmMap[id].category_id;
                document.getElementById('prm-modal-text').value = prmMap[id].parameter_text || '';
                document.getElementById('prm-modal-type').value = prmMap[id].type || 'rating';
                document.getElementById('prm-modal-max').value = prmMap[id].max_value === null ? '' : prmMap[id].max_value;
                document.getElementById('prm-modal-wt').value = prmMap[id].score_weightage;
                document.getElementById('prm-modal-sort').value = prmMap[id].sort_order;
                document.getElementById('prm-modal-active').checked = !!prmMap[id].is_active;
            } else {
                document.getElementById('prm-modal-title').textContent = 'Add Parameter';
            }
            auditPrmToggleMax();
            auditModalOpen('prmModal');
            setTimeout(function(){ document.getElementById('prm-modal-text').focus(); }, 50);
        };

        // Tree expand/collapse
        window.auditTreeToggle = function(btn){
            var row = btn.closest('.audit-tree-cat');
            if (!row) return;
            var kids = row.querySelector('.audit-tree-children');
            if (!kids) return;
            var open = btn.getAttribute('aria-expanded') !== 'false';
            btn.setAttribute('aria-expanded', open ? 'false' : 'true');
            kids.classList.toggle('collapsed', open);
        };

        // Inline name field — track dirty state to enable Save
        var nameInput = document.getElementById('tpl-name-input');
        var saveBtn   = document.getElementById('tplSaveBtn');
        if (nameInput && saveBtn) {
            nameInput.addEventListener('input', function(){
                var dirty = (nameInput.value || '').trim() !== (nameInput.dataset.original || '').trim();
                saveBtn.disabled = !dirty || !nameInput.value.trim();
            });
        }
        window.auditTplCancelName = function(){
            if (nameInput) {
                nameInput.value = nameInput.dataset.original || '';
                if (saveBtn) saveBtn.disabled = true;
            }
        };
    })();
    </script>
    <?php
}

// ===========================================================
// PAGE: Audit Categories (admin)
// ===========================================================
function pageAuditCategories(): void {
    if (!auditCanAdmin()) { echo '<p>Access denied.</p>'; return; }
    $templateFilter = (int)($_GET['template_id'] ?? 0);
    $templates = auditGetTemplates(false);
    $cats = auditGetCategories($templateFilter);
    ?>
    <div class="page-header"><h2>Audit Categories</h2></div>
    <form method="POST" class="form-card" style="margin-bottom:18px">
        <input type="hidden" name="action" value="save_audit_category">
        <div class="form-grid">
            <div class="form-group">
                <label>ID</label>
                <input class="form-control" name="id" id="cat-id" readonly placeholder="(new)">
            </div>
            <div class="form-group">
                <label>Template <span class="required">*</span></label>
                <select class="form-control" name="template_id" id="cat-tpl" required>
                    <option value="">— Select —</option>
                    <?php foreach ($templates as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $templateFilter === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Name <span class="required">*</span></label>
                <input class="form-control" name="name" id="cat-name" required>
            </div>
            <div class="form-group">
                <label>Weightage <span class="required">*</span></label>
                <input type="number" step="0.01" class="form-control" name="weightage" id="cat-wt" required>
            </div>
            <div class="form-group">
                <label>Sort Order</label>
                <input type="number" class="form-control" name="sort_order" id="cat-sort" value="0">
            </div>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary">Save Category</button>
            <button type="button" class="btn btn-ghost" onclick="resetCat()">Reset</button>
        </div>
    </form>

    <form method="GET" class="filter-bar">
        <input type="hidden" name="page" value="audit_categories">
        <select name="template_id" class="form-control" style="max-width:280px" onchange="this.form.submit()">
            <option value="0">— All Templates —</option>
            <?php foreach ($templates as $t): ?>
                <option value="<?= $t['id'] ?>" <?= $templateFilter === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <div class="table-wrap" data-stack>
        <table class="table">
            <thead><tr><th>ID</th><th>Template</th><th>Name</th><th>Weightage</th><th>Sort</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$cats): ?>
                <tr><td colspan="6" class="empty-row">No categories.</td></tr>
            <?php else: foreach ($cats as $c): ?>
                <tr>
                    <td data-label="ID"><?= (int)$c['id'] ?></td>
                    <td data-label="Template"><?= h($c['template_name']) ?></td>
                    <td data-label="Name"><?= h($c['name']) ?></td>
                    <td data-label="Weightage"><?= number_format((float)$c['weightage'], 2) ?></td>
                    <td data-label="Sort"><?= (int)$c['sort_order'] ?></td>
                    <td data-label="Actions" class="actions">
                        <a class="btn btn-sm btn-secondary" href="?page=audit_parameters&category_id=<?= (int)$c['id'] ?>">Parameters</a>
                        <button class="btn btn-sm btn-primary" type="button" onclick="editCat(<?= (int)$c['id'] ?>, <?= (int)$c['template_id'] ?>, <?= htmlspecialchars(json_encode($c['name']), ENT_QUOTES) ?>, <?= (float)$c['weightage'] ?>, <?= (int)$c['sort_order'] ?>)">Edit</button>
                        <form method="POST" class="inline-form" onsubmit="return confirm('Delete category and ALL its parameters?')">
                            <input type="hidden" name="action" value="del_audit_category">
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <button class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <script>
    function editCat(id,tpl,name,wt,sort){
        document.getElementById('cat-id').value=id;
        document.getElementById('cat-tpl').value=tpl;
        document.getElementById('cat-name').value=name;
        document.getElementById('cat-wt').value=wt;
        document.getElementById('cat-sort').value=sort;
        window.scrollTo({top:0, behavior:'smooth'});
    }
    function resetCat(){['cat-id','cat-name','cat-wt'].forEach(function(x){document.getElementById(x).value='';});document.getElementById('cat-sort').value='0';}
    </script>
    <?php
}

// ===========================================================
// PAGE: Audit Parameters (admin)
// ===========================================================
function pageAuditParameters(): void {
    if (!auditCanAdmin()) { echo '<p>Access denied.</p>'; return; }
    $categoryFilter = (int)($_GET['category_id'] ?? 0);
    $templateFilter = (int)($_GET['template_id'] ?? 0);
    $templates = auditGetTemplates(false);
    $cats = auditGetCategories($templateFilter);
    $params = auditGetParameters($categoryFilter);
    ?>
    <div class="page-header"><h2>Audit Parameters</h2></div>
    <form method="POST" class="form-card" style="margin-bottom:18px">
        <input type="hidden" name="action" value="save_audit_parameter">
        <div class="form-grid">
            <div class="form-group">
                <label>ID</label>
                <input class="form-control" name="id" id="prm-id" readonly placeholder="(new)">
            </div>
            <div class="form-group">
                <label>Category <span class="required">*</span></label>
                <select class="form-control" name="category_id" id="prm-cat" required>
                    <option value="">— Select —</option>
                    <?php foreach (auditGetCategories() as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $categoryFilter === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['template_name']) ?> / <?= h($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label>Parameter Text <span class="required">*</span></label>
                <textarea class="form-control" name="parameter_text" id="prm-text" rows="2" required></textarea>
            </div>
            <div class="form-group">
                <label>Type <span class="required">*</span></label>
                <select class="form-control" name="type" id="prm-type" required onchange="toggleMax()">
                    <option value="rating">Rating (1–5)</option>
                    <option value="value">Value (numeric)</option>
                    <option value="boolean">Boolean (Yes/No)</option>
                </select>
            </div>
            <div class="form-group" id="prm-max-wrap" style="display:none">
                <label>Max Value (for Value type)</label>
                <input type="number" step="0.01" class="form-control" name="max_value" id="prm-max">
            </div>
            <div class="form-group">
                <label>Score Weightage <span class="required">*</span></label>
                <input type="number" step="0.01" class="form-control" name="score_weightage" id="prm-wt" required>
            </div>
            <div class="form-group">
                <label>Sort Order</label>
                <input type="number" class="form-control" name="sort_order" id="prm-sort" value="0">
            </div>
            <div class="form-group">
                <label class="checkbox-label"><input type="checkbox" name="is_active" id="prm-active" checked value="1"> Active</label>
            </div>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary">Save Parameter</button>
            <button type="button" class="btn btn-ghost" onclick="resetPrm()">Reset</button>
        </div>
    </form>

    <form method="GET" class="filter-bar">
        <input type="hidden" name="page" value="audit_parameters">
        <select name="category_id" class="form-control" style="max-width:360px" onchange="this.form.submit()">
            <option value="0">— All Categories —</option>
            <?php foreach (auditGetCategories() as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $categoryFilter === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['template_name']) ?> / <?= h($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <div class="table-wrap" data-stack>
        <table class="table">
            <thead><tr><th>ID</th><th>Category</th><th>Parameter</th><th>Type</th><th>Max</th><th>Score Wt.</th><th>Sort</th><th>Active</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$params): ?>
                <tr><td colspan="9" class="empty-row">No parameters.</td></tr>
            <?php else: foreach ($params as $p): ?>
                <tr>
                    <td data-label="ID"><?= (int)$p['id'] ?></td>
                    <td data-label="Category"><?= h($p['template_name']) ?> / <?= h($p['category_name']) ?></td>
                    <td data-label="Parameter"><?= h($p['parameter_text']) ?></td>
                    <td data-label="Type"><?= h($p['type']) ?></td>
                    <td data-label="Max"><?= $p['max_value'] !== null ? h($p['max_value']) : '—' ?></td>
                    <td data-label="Score Wt."><?= number_format((float)$p['score_weightage'], 2) ?></td>
                    <td data-label="Sort"><?= (int)$p['sort_order'] ?></td>
                    <td data-label="Active"><?= $p['is_active'] ? '<span class="badge badge-green">Yes</span>' : '<span class="badge badge-grey">No</span>' ?></td>
                    <td data-label="Actions" class="actions">
                        <button class="btn btn-sm btn-primary" type="button" onclick='editPrm(<?= (int)$p['id'] ?>, <?= (int)$p['category_id'] ?>, <?= json_encode($p['parameter_text']) ?>, <?= json_encode($p['type']) ?>, <?= $p['max_value'] === null ? 'null' : (float)$p['max_value'] ?>, <?= (float)$p['score_weightage'] ?>, <?= (int)$p['sort_order'] ?>, <?= (int)$p['is_active'] ?>)'>Edit</button>
                        <form method="POST" class="inline-form" onsubmit="return confirm('Delete parameter?')">
                            <input type="hidden" name="action" value="del_audit_parameter">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <script>
    function toggleMax(){
        var t = document.getElementById('prm-type').value;
        document.getElementById('prm-max-wrap').style.display = (t === 'value') ? '' : 'none';
    }
    function editPrm(id,cat,txt,type,max,wt,sort,active){
        document.getElementById('prm-id').value=id;
        document.getElementById('prm-cat').value=cat;
        document.getElementById('prm-text').value=txt;
        document.getElementById('prm-type').value=type;
        document.getElementById('prm-max').value = (max === null ? '' : max);
        document.getElementById('prm-wt').value=wt;
        document.getElementById('prm-sort').value=sort;
        document.getElementById('prm-active').checked = !!active;
        toggleMax();
        window.scrollTo({top:0, behavior:'smooth'});
    }
    function resetPrm(){['prm-id','prm-text','prm-max','prm-wt'].forEach(function(x){document.getElementById(x).value='';});document.getElementById('prm-type').value='rating';document.getElementById('prm-sort').value='0';document.getElementById('prm-active').checked=true;toggleMax();}
    toggleMax();
    </script>
    <?php
}

// ===========================================================
// POST HANDLERS — Template / Category / Parameter CRUD
// ===========================================================
// All POST handlers below use auditAdminBackTo() to decide where to send
// the user after success. The unified Templates page is now the canonical
// admin UI, so anything posted from there returns to it (with the
// originating template_id preserved). Posts from the legacy
// audit_categories / audit_parameters pages still go back to those pages.
function auditAdminBackTo(string $fallback): string {
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if ($ref && stripos($ref, 'page=audit_templates') !== false) {
        return $ref; // preserves &template_id=...
    }
    return $fallback;
}

function doSaveAuditTemplate(): void {
    if (!auditCanAdmin()) { header('Location: ?page=audit_templates'); return; }
    $id     = (int)($_POST['id'] ?? 0);
    $name   = trim($_POST['name'] ?? '');
    $active = !empty($_POST['is_active']) ? 1 : 0;
    if ($name === '') { flash('error', 'Name required.'); header('Location: ?page=audit_templates'); return; }
    $db = getDb();
    $newId = $id;
    try {
        if ($id > 0) {
            $db->prepare('UPDATE audit_templates SET name=?, is_active=? WHERE id=?')->execute([$name, $active, $id]);
        } else {
            $db->prepare('INSERT INTO audit_templates (name, is_active) VALUES (?, ?)')->execute([$name, $active]);
            $newId = (int)$db->lastInsertId();
        }
        flash('success', 'Template saved.');
    } catch (Exception $e) { flash('error', 'Save failed: ' . $e->getMessage()); }
    header('Location: ?page=audit_templates' . ($newId > 0 ? '&template_id=' . $newId : ''));
}
function doDelAuditTemplate(): void {
    if (!auditCanAdmin()) { header('Location: ?page=audit_templates'); return; }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) getDb()->prepare('DELETE FROM audit_templates WHERE id=?')->execute([$id]);
    flash('success', 'Template deleted.');
    header('Location: ?page=audit_templates');
}
function doSaveAuditCategory(): void {
    if (!auditCanAdmin()) { header('Location: ?page=audit_categories'); return; }
    $id    = (int)($_POST['id'] ?? 0);
    $tpl   = (int)($_POST['template_id'] ?? 0);
    $name  = trim($_POST['name'] ?? '');
    $wt    = (float)($_POST['weightage'] ?? 0);
    $sort  = (int)($_POST['sort_order'] ?? 0);
    if (!$tpl || $name === '') { flash('error', 'Template and name required.'); header('Location: ' . auditAdminBackTo('?page=audit_categories')); return; }
    $db = getDb();
    try {
        if ($id > 0) {
            $db->prepare('UPDATE audit_categories SET template_id=?, name=?, weightage=?, sort_order=? WHERE id=?')->execute([$tpl,$name,$wt,$sort,$id]);
        } else {
            $db->prepare('INSERT INTO audit_categories (template_id, name, weightage, sort_order) VALUES (?,?,?,?)')->execute([$tpl,$name,$wt,$sort]);
        }
        flash('success', 'Category saved.');
    } catch (Exception $e) { flash('error', 'Save failed: ' . $e->getMessage()); }
    header('Location: ' . auditAdminBackTo('?page=audit_categories&template_id=' . $tpl));
}
function doDelAuditCategory(): void {
    if (!auditCanAdmin()) { header('Location: ?page=audit_categories'); return; }
    $id = (int)($_POST['id'] ?? 0);
    // Resolve the parent template before deleting so we can return there
    $tpl = 0;
    if ($id > 0) {
        $tpl = (int)getDb()->query('SELECT template_id FROM audit_categories WHERE id = ' . $id)->fetchColumn();
        getDb()->prepare('DELETE FROM audit_categories WHERE id=?')->execute([$id]);
    }
    flash('success', 'Category deleted.');
    header('Location: ' . auditAdminBackTo($tpl > 0 ? '?page=audit_templates&template_id=' . $tpl : '?page=audit_categories'));
}
function doSaveAuditParameter(): void {
    if (!auditCanAdmin()) { header('Location: ?page=audit_parameters'); return; }
    $id    = (int)($_POST['id'] ?? 0);
    $cat   = (int)($_POST['category_id'] ?? 0);
    $text  = trim($_POST['parameter_text'] ?? '');
    $type  = $_POST['type'] ?? 'rating';
    if (!in_array($type, ['rating','value','boolean'], true)) $type = 'rating';
    $max   = $type === 'value' ? (isset($_POST['max_value']) && $_POST['max_value'] !== '' ? (float)$_POST['max_value'] : null) : null;
    $wt    = (float)($_POST['score_weightage'] ?? 0);
    $sort  = (int)($_POST['sort_order'] ?? 0);
    $active = !empty($_POST['is_active']) ? 1 : 0;
    if (!$cat || $text === '') { flash('error', 'Category and text required.'); header('Location: ' . auditAdminBackTo('?page=audit_parameters')); return; }
    if ($type === 'value' && ($max === null || $max <= 0)) { flash('error', 'Max Value required for Value type.'); header('Location: ' . auditAdminBackTo('?page=audit_parameters')); return; }
    $db = getDb();
    try {
        if ($id > 0) {
            $db->prepare('UPDATE audit_parameters SET category_id=?, parameter_text=?, type=?, max_value=?, score_weightage=?, sort_order=?, is_active=? WHERE id=?')
               ->execute([$cat,$text,$type,$max,$wt,$sort,$active,$id]);
        } else {
            $db->prepare('INSERT INTO audit_parameters (category_id, parameter_text, type, max_value, score_weightage, sort_order, is_active) VALUES (?,?,?,?,?,?,?)')
               ->execute([$cat,$text,$type,$max,$wt,$sort,$active]);
        }
        flash('success', 'Parameter saved.');
    } catch (Exception $e) { flash('error', 'Save failed: ' . $e->getMessage()); }
    header('Location: ' . auditAdminBackTo('?page=audit_parameters&category_id=' . $cat));
}
function doDelAuditParameter(): void {
    if (!auditCanAdmin()) { header('Location: ?page=audit_parameters'); return; }
    $id = (int)($_POST['id'] ?? 0);
    $tpl = 0;
    if ($id > 0) {
        $db = getDb();
        $tpl = (int)$db->query('SELECT c.template_id FROM audit_parameters p JOIN audit_categories c ON c.id = p.category_id WHERE p.id = ' . $id)->fetchColumn();
        // Refuse to hard-delete a parameter that audit_responses already
        // reference — the FK constraint would crash, and even if it didn't,
        // wiping the row would orphan past audit data. Soft-delete (mark
        // inactive) so historical audits stay readable while the parameter
        // disappears from new audits.
        $stUsed = $db->prepare('SELECT 1 FROM audit_responses WHERE parameter_id = ? LIMIT 1');
        $stUsed->execute([$id]);
        $inUse = (bool)$stUsed->fetchColumn();
        if ($inUse) {
            $db->prepare('UPDATE audit_parameters SET is_active = 0 WHERE id = ?')->execute([$id]);
            flash('success', 'Parameter is referenced by past audits — marked inactive instead of deleted.');
        } else {
            try {
                $db->prepare('DELETE FROM audit_parameters WHERE id=?')->execute([$id]);
                flash('success', 'Parameter deleted.');
            } catch (Exception $e) {
                // Race: a response may have landed between the check and
                // the delete. Fall back to soft-delete.
                $db->prepare('UPDATE audit_parameters SET is_active = 0 WHERE id = ?')->execute([$id]);
                flash('success', 'Parameter is referenced by an audit — marked inactive instead of deleted.');
            }
        }
    }
    header('Location: ' . auditAdminBackTo($tpl > 0 ? '?page=audit_templates&template_id=' . $tpl : '?page=audit_parameters'));
}

// ===========================================================
// EXPORT: Audit Templates CSV
// One row per parameter, with the owning category + template
// repeated. Empty templates and categories still emit a row so
// the reader can audit the full structure even when nothing has
// been authored yet. ?template_id=N exports just that template;
// otherwise every template (active + inactive) is included.
// ===========================================================
function exportAuditTemplates(): void {
    if (!auditCanAdmin()) { http_response_code(403); echo 'Access denied.'; return; }

    $tplFilter = (int)($_GET['template_id'] ?? 0);

    try {
        $sql    = 'SELECT id, name, is_active FROM audit_templates';
        $params = [];
        if ($tplFilter > 0) { $sql .= ' WHERE id = ?'; $params[] = $tplFilter; }
        $sql   .= ' ORDER BY is_active DESC, name';
        $st     = getDb()->prepare($sql);
        $st->execute($params);
        $templates = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $templates = []; }

    // Pull every category + parameter for the selected templates in one
    // shot, then group in PHP so the CSV is emitted in a stable order
    // (template → category sort_order → parameter sort_order).
    $cats = $params = [];
    if ($templates) {
        $tplIds = array_map(fn($t) => (int)$t['id'], $templates);
        $ph     = implode(',', array_fill(0, count($tplIds), '?'));
        try {
            $st = getDb()->prepare("SELECT id, template_id, name, weightage, sort_order
                                    FROM audit_categories
                                    WHERE template_id IN ($ph)
                                    ORDER BY template_id, sort_order, id");
            $st->execute($tplIds);
            $cats = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $cats = []; }

        if ($cats) {
            $catIds = array_map(fn($c) => (int)$c['id'], $cats);
            $cph    = implode(',', array_fill(0, count($catIds), '?'));
            try {
                $st = getDb()->prepare("SELECT id, category_id, parameter_text, type, max_value,
                                               score_weightage, sort_order, is_active
                                        FROM audit_parameters
                                        WHERE category_id IN ($cph)
                                        ORDER BY category_id, sort_order, id");
                $st->execute($catIds);
                $params = $st->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $params = []; }
        }
    }

    $catsByTpl   = [];
    foreach ($cats   as $c) $catsByTpl[(int)$c['template_id']][] = $c;
    $paramsByCat = [];
    foreach ($params as $p) $paramsByCat[(int)$p['category_id']][] = $p;

    // Filename: include the template name when filtered to a single
    // template, otherwise mark as ALL.
    $slug = 'ALL';
    if ($tplFilter > 0 && $templates) {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '_', (string)$templates[0]['name']) ?: ('tpl_' . $tplFilter);
    }
    $filename = 'audit_templates_' . $slug . '_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');

    // PHP 8.4: fputcsv() emits a deprecation warning when the escape
    // character is left implicit. Pass escape: '' on every call —
    // matches the pattern every other export in this codebase uses.
    fputcsv($out, ['Audit Templates Export'], escape: '');
    fputcsv($out, ['Generated', date('Y-m-d H:i:s')], escape: '');
    fputcsv($out, ['Templates',  count($templates),
                   'Categories', count($cats),
                   'Parameters', count($params)], escape: '');
    fputcsv($out, [], escape: '');

    fputcsv($out, [
        'Template ID', 'Template Name', 'Template Active',
        'Category ID', 'Category Name', 'Category Weightage', 'Category Sort',
        'Parameter ID', 'Parameter Text', 'Parameter Type', 'Max Value',
        'Score Weightage', 'Parameter Sort', 'Parameter Active',
    ], escape: '');

    foreach ($templates as $t) {
        $tplId = (int)$t['id'];
        $tplCs = $catsByTpl[$tplId] ?? [];

        if (!$tplCs) {
            fputcsv($out, [
                $tplId, $t['name'], $t['is_active'] ? 'Yes' : 'No',
                '', '(no categories)', '', '',
                '', '', '', '', '', '', '',
            ], escape: '');
            continue;
        }

        foreach ($tplCs as $c) {
            $catId = (int)$c['id'];
            $list  = $paramsByCat[$catId] ?? [];

            if (!$list) {
                fputcsv($out, [
                    $tplId, $t['name'], $t['is_active'] ? 'Yes' : 'No',
                    $catId, $c['name'], number_format((float)$c['weightage'], 2, '.', ''), (int)$c['sort_order'],
                    '', '(no parameters)', '', '', '', '', '',
                ], escape: '');
                continue;
            }

            foreach ($list as $p) {
                fputcsv($out, [
                    $tplId, $t['name'], $t['is_active'] ? 'Yes' : 'No',
                    $catId, $c['name'], number_format((float)$c['weightage'], 2, '.', ''), (int)$c['sort_order'],
                    (int)$p['id'], $p['parameter_text'], $p['type'],
                    $p['max_value'] !== null ? number_format((float)$p['max_value'], 2, '.', '') : '',
                    number_format((float)$p['score_weightage'], 2, '.', ''),
                    (int)$p['sort_order'],
                    $p['is_active'] ? 'Yes' : 'No',
                ], escape: '');
            }
        }
    }

    fclose($out);
    exit;
}
