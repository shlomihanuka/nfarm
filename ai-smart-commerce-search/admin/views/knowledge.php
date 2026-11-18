<?php
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_options' ) ) {
    return;
}

$rest_url  = esc_url( rest_url( 'asc/v1' ) );
$rest_nonce = wp_create_nonce( 'wp_rest' );
?>
<div class="wrap asc-knowledge-wrap">
    <h1><?php esc_html_e( 'Catalog Intelligence — Knowledge Panel', 'ai-smart-commerce-search' ); ?></h1>
    <p><?php esc_html_e( 'Enrich products with inferred intelligence. All inferred fields are marked review_required until a human confirms them. Allergens and ingredients are never inferred.', 'ai-smart-commerce-search' ); ?></p>

    <div class="asc-knowledge-toolbar">
        <button id="asc-run-batch" class="button button-primary">
            <?php esc_html_e( 'Enrich Next 20 Products (stub)', 'ai-smart-commerce-search' ); ?>
        </button>
        <input type="number" id="asc-batch-limit" value="20" min="1" max="100" style="width:60px;margin-left:8px;" />
        <span id="asc-batch-status" style="margin-left:12px;font-style:italic;color:#666;"></span>
    </div>

    <div id="asc-batch-result" style="margin-top:16px;"></div>

    <hr />

    <h2><?php esc_html_e( 'Stored Profiles', 'ai-smart-commerce-search' ); ?></h2>
    <p>
        <label><?php esc_html_e( 'Filter by state:', 'ai-smart-commerce-search' ); ?>
            <select id="asc-filter-state">
                <option value=""><?php esc_html_e( 'All', 'ai-smart-commerce-search' ); ?></option>
                <option value="pending_review"><?php esc_html_e( 'Pending Review', 'ai-smart-commerce-search' ); ?></option>
                <option value="approved"><?php esc_html_e( 'Approved', 'ai-smart-commerce-search' ); ?></option>
                <option value="rejected"><?php esc_html_e( 'Rejected', 'ai-smart-commerce-search' ); ?></option>
            </select>
        </label>
        <button id="asc-load-profiles" class="button" style="margin-left:8px;">
            <?php esc_html_e( 'Load', 'ai-smart-commerce-search' ); ?>
        </button>
    </p>
    <div id="asc-profiles-table"></div>

    <div id="asc-profile-detail" style="display:none;margin-top:24px;background:#fff;border:1px solid #ccc;padding:16px;">
        <h3 id="asc-profile-detail-title"></h3>
        <pre id="asc-profile-detail-json" style="max-height:400px;overflow:auto;font-size:12px;background:#f9f9f9;padding:12px;"></pre>
        <button id="asc-profile-close" class="button"><?php esc_html_e( 'Close', 'ai-smart-commerce-search' ); ?></button>
    </div>

    <h2><?php esc_html_e( 'Recipe Decompose (stub demo)', 'ai-smart-commerce-search' ); ?></h2>
    <p>
        <input type="text" id="asc-recipe-query" placeholder="<?php esc_attr_e( 'ארוחת שבת לחמישה אנשים', 'ai-smart-commerce-search' ); ?>" style="width:360px;" />
        <button id="asc-recipe-go" class="button" style="margin-left:8px;"><?php esc_html_e( 'Decompose', 'ai-smart-commerce-search' ); ?></button>
    </p>
    <pre id="asc-recipe-result" style="display:none;background:#f9f9f9;padding:12px;font-size:12px;max-height:320px;overflow:auto;"></pre>
</div>

<style>
.asc-knowledge-wrap { max-width: 1100px; }
.asc-knowledge-toolbar { display:flex; align-items:center; margin-bottom:8px; }
#asc-profiles-table table { border-collapse:collapse; width:100%; }
#asc-profiles-table th, #asc-profiles-table td { padding:6px 10px; border:1px solid #ddd; font-size:13px; }
#asc-profiles-table th { background:#f1f1f1; }
.asc-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; }
.asc-badge-pending  { background:#fff3cd; color:#856404; }
.asc-badge-approved { background:#d4edda; color:#155724; }
.asc-badge-rejected { background:#f8d7da; color:#721c24; }
.asc-review-fields  { font-size:11px; color:#888; }
</style>

<script>
(function() {
    var REST  = <?php echo wp_json_encode( $rest_url ); ?>;
    var NONCE = <?php echo wp_json_encode( $rest_nonce ); ?>;

    function apiFetch( path, method, body ) {
        return fetch( REST + path, {
            method: method || 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': NONCE,
            },
            body: body ? JSON.stringify( body ) : undefined,
        } ).then( r => r.json() );
    }

    // --- Batch enrichment ---
    document.getElementById('asc-run-batch').addEventListener('click', function() {
        var limit = parseInt( document.getElementById('asc-batch-limit').value, 10 ) || 20;
        var status = document.getElementById('asc-batch-status');
        var result = document.getElementById('asc-batch-result');
        status.textContent = '<?php esc_html_e( 'Running…', 'ai-smart-commerce-search' ); ?>';
        result.innerHTML = '';

        apiFetch( '/enrich/batch', 'POST', { limit: limit } ).then( data => {
            status.textContent = '';
            var html = '<table style="border-collapse:collapse;width:100%;">';
            html += '<tr><th style="text-align:left;padding:4px 8px;">Product</th>';
            html += '<th style="text-align:left;padding:4px 8px;">State</th>';
            html += '<th style="text-align:left;padding:4px 8px;">Needs Review</th>';
            html += '<th style="text-align:left;padding:4px 8px;">Fields</th></tr>';

            (data.profiles || []).forEach( function(p) {
                html += '<tr>';
                html += '<td style="padding:4px 8px;">' + escHtml(p.name) + ' <small>(#' + p.product_id + ')</small></td>';
                html += '<td style="padding:4px 8px;"><span class="asc-badge asc-badge-pending">' + escHtml(p.approval_state) + '</span></td>';
                html += '<td style="padding:4px 8px;">' + p.needs_review_count + ' / ' + p.fields_needing_review.length + '</td>';
                html += '<td style="padding:4px 8px;" class="asc-review-fields">' + p.fields_needing_review.join(', ') + '</td>';
                html += '</tr>';
            });
            html += '</table>';

            html += '<p><strong><?php esc_html_e( 'Enriched:', 'ai-smart-commerce-search' ); ?> </strong>' + data.enriched + '/' + data.requested;
            if ( data.errors > 0 ) html += ' &nbsp; <strong><?php esc_html_e( 'Errors:', 'ai-smart-commerce-search' ); ?></strong> ' + data.errors;
            html += '</p>';

            if ( data.fields_needing_review_counts && Object.keys(data.fields_needing_review_counts).length ) {
                html += '<p><strong><?php esc_html_e( 'Review frequency across batch:', 'ai-smart-commerce-search' ); ?></strong></p><ul>';
                Object.entries(data.fields_needing_review_counts).forEach( ([f, n]) => {
                    html += '<li>' + escHtml(f) + ': ' + n + '</li>';
                });
                html += '</ul>';
            }

            result.innerHTML = html;
        }).catch( e => {
            status.textContent = '<?php esc_html_e( 'Error — see console.', 'ai-smart-commerce-search' ); ?>';
            console.error(e);
        });
    });

    // --- Load profiles ---
    document.getElementById('asc-load-profiles').addEventListener('click', loadProfiles);

    function loadProfiles() {
        var state = document.getElementById('asc-filter-state').value;
        var qs    = state ? '?approval_state=' + encodeURIComponent(state) : '';
        var wrap  = document.getElementById('asc-profiles-table');
        wrap.innerHTML = '<p><?php esc_html_e( 'Loading…', 'ai-smart-commerce-search' ); ?></p>';

        apiFetch( '/profiles' + qs ).then( data => {
            if ( !data.profiles || !data.profiles.length ) {
                wrap.innerHTML = '<p><?php esc_html_e( 'No profiles found. Run enrichment first.', 'ai-smart-commerce-search' ); ?></p>';
                return;
            }
            var html = '<p><em>' + data.total + ' <?php esc_html_e( 'total', 'ai-smart-commerce-search' ); ?></em></p>';
            html += '<table><tr><th><?php esc_html_e( 'Product ID', 'ai-smart-commerce-search' ); ?></th>';
            html += '<th><?php esc_html_e( 'Source', 'ai-smart-commerce-search' ); ?></th>';
            html += '<th><?php esc_html_e( 'State', 'ai-smart-commerce-search' ); ?></th>';
            html += '<th><?php esc_html_e( 'Enriched', 'ai-smart-commerce-search' ); ?></th>';
            html += '<th><?php esc_html_e( 'Actions', 'ai-smart-commerce-search' ); ?></th></tr>';

            data.profiles.forEach( function(p) {
                var badge = 'asc-badge-' + p.approval_state.replace('_', '-').replace('pending-review', 'pending');
                html += '<tr>';
                html += '<td><a href="#" class="asc-view-profile" data-id="' + p.product_id + '">#' + p.product_id + '</a></td>';
                html += '<td>' + escHtml(p.enrichment_source) + '</td>';
                html += '<td><span class="asc-badge ' + badge + '">' + escHtml(p.approval_state) + '</span></td>';
                html += '<td>' + escHtml(p.enriched_at) + '</td>';
                html += '<td>';
                if ( 'approved' !== p.approval_state ) {
                    html += '<button class="button button-small asc-approve-btn" data-id="' + p.product_id + '" data-action="approve"><?php esc_html_e( 'Approve', 'ai-smart-commerce-search' ); ?></button> ';
                }
                if ( 'rejected' !== p.approval_state ) {
                    html += '<button class="button button-small asc-approve-btn" data-id="' + p.product_id + '" data-action="reject"><?php esc_html_e( 'Reject', 'ai-smart-commerce-search' ); ?></button>';
                }
                html += '</td></tr>';
            });
            html += '</table>';
            wrap.innerHTML = html;

            // View profile detail
            wrap.querySelectorAll('.asc-view-profile').forEach( function(a) {
                a.addEventListener('click', function(e) {
                    e.preventDefault();
                    var id = this.dataset.id;
                    apiFetch('/profiles/' + id).then( profile => {
                        document.getElementById('asc-profile-detail-title').textContent = '#' + id + ' — ' + (profile.verified && profile.verified.name || '');
                        document.getElementById('asc-profile-detail-json').textContent = JSON.stringify(profile, null, 2);
                        document.getElementById('asc-profile-detail').style.display = 'block';
                        document.getElementById('asc-profile-detail').scrollIntoView({behavior:'smooth'});
                    });
                });
            });

            // Approve/reject
            wrap.querySelectorAll('.asc-approve-btn').forEach( function(btn) {
                btn.addEventListener('click', function() {
                    var id     = this.dataset.id;
                    var action = this.dataset.action;
                    apiFetch('/profiles/' + id + '/approve', 'POST', { action: action }).then( () => loadProfiles() );
                });
            });
        }).catch( e => {
            wrap.innerHTML = '<p><?php esc_html_e( 'Error — see console.', 'ai-smart-commerce-search' ); ?></p>';
            console.error(e);
        });
    }

    // Close profile detail
    document.getElementById('asc-profile-close').addEventListener('click', function() {
        document.getElementById('asc-profile-detail').style.display = 'none';
    });

    // --- Recipe decompose ---
    document.getElementById('asc-recipe-go').addEventListener('click', function() {
        var q   = document.getElementById('asc-recipe-query').value;
        var out = document.getElementById('asc-recipe-result');
        if ( !q ) return;
        apiFetch('/recipe/decompose?q=' + encodeURIComponent(q)).then( data => {
            out.style.display = 'block';
            out.textContent   = JSON.stringify(data, null, 2);
        });
    });

    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
})();
</script>
