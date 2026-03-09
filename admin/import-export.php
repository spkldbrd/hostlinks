<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

// Notification messages
$hl_msg      = isset( $_GET['hl_msg'] )      ? sanitize_key( $_GET['hl_msg'] )      : '';
$hl_imported = isset( $_GET['hl_imported'] ) ? intval( $_GET['hl_imported'] )        : 0;
$hl_skipped  = isset( $_GET['hl_skipped'] )  ? intval( $_GET['hl_skipped'] )         : 0;
$hl_failed   = isset( $_GET['hl_failed'] )   ? intval( $_GET['hl_failed'] )          : 0;
$hl_error    = isset( $_GET['hl_error'] )    ? sanitize_text_field( urldecode( $_GET['hl_error'] ) ) : '';

// Build the import result notice.
$import_notice = '';
if ( $hl_msg === 'imported' ) {
	$level  = ( $hl_failed > 0 ) ? 'notice-warning' : 'notice-success';
	$import_notice .= "<div class=\"notice {$level}\"><p>";
	$import_notice .= "Import complete: <strong>{$hl_imported}</strong> inserted, <strong>{$hl_skipped}</strong> skipped (duplicates)";
	if ( $hl_failed > 0 ) {
		$import_notice .= ", <strong style=\"color:#d63638;\">{$hl_failed} failed</strong>";
	}
	$import_notice .= '.</p>';
	if ( $hl_error ) {
		$import_notice .= '<p><strong>First error:</strong> <code>' . esc_html( $hl_error ) . '</code></p>';
	}
	$import_notice .= '</div>';

	// Post-import row count summary — show what is now in each table.
	$counts = array(
		'Events'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}event_details_list" ),
		'Types'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}event_type" ),
		'Marketers'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}event_marketer" ),
		'Instructors' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}event_instructor" ),
	);
	$import_notice .= '<div class="notice notice-info" style="padding-bottom:10px;"><p><strong>Database row counts after import:</strong></p>';
	$import_notice .= '<table style="border-collapse:collapse;margin-top:4px;">';
	foreach ( $counts as $label => $count ) {
		$color = ( $count === 0 ) ? 'color:#d63638;' : '';
		$import_notice .= "<tr><td style=\"padding:2px 16px 2px 0;{$color}\"><strong>{$label}</strong></td><td style=\"{$color}\">{$count}</td></tr>";
	}
	$import_notice .= '</table>';
	if ( in_array( 0, $counts, true ) ) {
		$import_notice .= '<p style="color:#d63638;">&#9888; One or more tables are empty — check the error details above.</p>';
	}
	$import_notice .= '</div>';
}

// Reset result notice
$reset_notice = '';
if ( isset( $_GET['hl_msg'] ) && $_GET['hl_msg'] === 'reset_done' ) {
	$cleared_raw  = sanitize_text_field( $_GET['hl_reset_keys'] ?? '' );
	$cleared_keys = array_filter( explode( ',', $cleared_raw ) );
	$label_map = array(
		'events'      => 'Events',
		'marketers'   => 'Marketers',
		'instructors' => 'Instructors',
		'types'       => 'Types',
		'requests'    => 'Event Requests',
	);
	$cleared_labels = array_map( function( $k ) use ( $label_map ) {
		return $label_map[ $k ] ?? $k;
	}, $cleared_keys );
	$reset_notice = '<div class="notice notice-success is-dismissible"><p><strong>Reset complete.</strong> The following tables were cleared: '
		. esc_html( implode( ', ', $cleared_labels ) ) . '.</p></div>';
}

$messages = array(
	'imported'          => $import_notice,
	'reset_done'        => $reset_notice,
	'reset_not_confirmed' => '<div class="notice notice-warning"><p>Reset cancelled — confirmation checkbox was not checked.</p></div>',
	'no_file'  => '<div class="notice notice-error"><p>No file was uploaded. Please select a file and try again.</p></div>',
	'bad_type' => '<div class="notice notice-error"><p>Invalid file type. Please upload a <strong>.json</strong> or <strong>.csv</strong> file.</p></div>',
	'bad_json' => '<div class="notice notice-error"><p>Could not parse the JSON file. Please check the file format and try again.</p></div>',
	'bad_csv'  => '<div class="notice notice-error"><p>Could not read the CSV file. Please check the file and try again.</p></div>',
);
?>
<div class="wrap">
  <h1>Hostlinks — Import / Export</h1>

  <?php if ( $hl_msg && isset( $messages[ $hl_msg ] ) ) echo $messages[ $hl_msg ]; ?>

  <nav class="nav-tab-wrapper" id="hl-ie-tabs">
    <a href="#hl-export" class="nav-tab nav-tab-active" onclick="hlTab(event,'hl-export')">Export</a>
    <a href="#hl-import" class="nav-tab" onclick="hlTab(event,'hl-import')">Import</a>
    <a href="#hl-reset"  class="nav-tab" onclick="hlTab(event,'hl-reset')" style="color:#b32d2e;">Reset Data</a>
  </nav>

  <!-- ══════ EXPORT ══════ -->
  <div id="hl-export" class="hl-tab-panel" style="margin-top:20px;">
    <h2>Export Data</h2>
    <p>Download all Hostlinks data to move it to another site or keep a backup.</p>

    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
      <?php wp_nonce_field( 'hostlinks_export' ); ?>
      <input type="hidden" name="action" value="hostlinks_export_json">
      <table class="form-table">
        <tr>
          <th>Full Export (JSON)</th>
          <td>
            <button type="submit" class="button button-primary">Download JSON</button>
            <p class="description">Exports all events, types, marketers, and instructors (including inactive) as a single <code>.json</code> file. Preserves all IDs so foreign key relationships survive a re-import on a new site.</p>
          </td>
        </tr>
      </table>
    </form>

    <hr>

    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
      <?php wp_nonce_field( 'hostlinks_export' ); ?>
      <input type="hidden" name="action" value="hostlinks_export_csv">
      <table class="form-table">
        <tr>
          <th>Events Only (CSV)</th>
          <td>
            <button type="submit" class="button">Download CSV</button>
            <p class="description">Exports the <code>event_details_list</code> table only as a <code>.csv</code> spreadsheet.</p>
          </td>
        </tr>
      </table>
    </form>
  </div>

  <!-- ══════ RESET ══════ -->
  <div id="hl-reset" class="hl-tab-panel" style="display:none;margin-top:20px;">
    <div style="background:#fff3f3;border:2px solid #b32d2e;border-radius:6px;padding:1.25rem 1.5rem;max-width:700px;">
      <h2 style="color:#b32d2e;margin-top:0;">⚠ Danger Zone — Clear Hostlinks Data</h2>
      <p>This permanently <strong>deletes</strong> all records from the selected tables. This action <strong>cannot be undone</strong>.</p>
      <p>Use this to clear duplicate or test data before performing a clean import. Export a backup first if you may need this data later.</p>

      <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" id="hl-reset-form">
        <?php wp_nonce_field( 'hostlinks_reset_data' ); ?>
        <input type="hidden" name="action" value="hostlinks_reset_data">

        <fieldset style="border:none;padding:0;margin:0 0 1rem;">
          <legend style="font-weight:700;margin-bottom:8px;">Select tables to clear:</legend>
          <?php
          $reset_tables = array(
            'events'      => 'Events — all event records',
            'marketers'   => 'Marketers — all marketer records',
            'instructors' => 'Instructors (Trainers) — all instructor records',
            'types'       => 'Types — all event type records',
            'requests'    => 'Event Requests — all pending intake requests',
          );
          foreach ( $reset_tables as $key => $label ) :
          ?>
          <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:14px;">
            <input type="checkbox" name="hl_reset_tables[]" value="<?php echo esc_attr($key); ?>" class="hl-reset-table-check" />
            <?php echo esc_html( $label ); ?>
          </label>
          <?php endforeach; ?>
          <label style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:14px;">
            <input type="checkbox" id="hl-reset-select-all" />
            <strong>Select all tables</strong>
          </label>
        </fieldset>

        <hr style="margin:1rem 0;">

        <label style="display:flex;align-items:flex-start;gap:10px;background:#fee;border:1px solid #f5a6a6;border-radius:4px;padding:12px;margin-bottom:1rem;">
          <input type="checkbox" name="hl_reset_confirmed" id="hl-reset-confirm" value="1" style="margin-top:2px;flex-shrink:0;" />
          <span>I understand this will <strong>permanently delete</strong> the selected data. I have made a backup or do not need this data.</span>
        </label>

        <button type="submit" id="hl-reset-submit" class="button"
          style="background:#b32d2e;border-color:#97231f;color:#fff;opacity:.45;cursor:not-allowed;"
          disabled>
          Clear Selected Data
        </button>
      </form>
    </div>

    <!-- Live row counts so the user can see what will be deleted -->
    <div style="margin-top:1.5rem;max-width:400px;">
      <h3 style="font-size:14px;">Current row counts</h3>
      <?php
      $count_map = array(
        'Events'         => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}event_details_list"),
        'Marketers'      => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}event_marketer"),
        'Instructors'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}event_instructor"),
        'Types'          => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}event_type"),
        'Event Requests' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}hostlinks_event_requests"),
      );
      ?>
      <table class="widefat striped" style="width:auto;">
        <thead><tr><th>Table</th><th>Rows</th></tr></thead>
        <tbody>
        <?php foreach ( $count_map as $lbl => $cnt ) : ?>
          <tr><td><?php echo esc_html($lbl); ?></td><td><?php echo $cnt; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ══════ IMPORT ══════ -->
  <div id="hl-import" class="hl-tab-panel" style="display:none;margin-top:20px;">
    <h2>Import Data</h2>
    <p>Upload a <strong>JSON</strong> (full export) or <strong>CSV</strong> (events only) file previously exported from Hostlinks.</p>
    <p><strong>Duplicate detection:</strong> Events are matched by <em>start date + location</em>. Types, marketers, and instructors are matched by name. Existing records will be skipped.</p>

    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
      <?php wp_nonce_field( 'hostlinks_import' ); ?>
      <input type="hidden" name="action" value="hostlinks_import">
      <table class="form-table">
        <tr>
          <th><label for="hostlinks_import_file">Select File</label></th>
          <td>
            <input type="file" id="hostlinks_import_file" name="hostlinks_import_file" accept=".json,.csv" required>
            <p class="description">Accepted formats: <code>.json</code> (full export) or <code>.csv</code> (events only).</p>
          </td>
        </tr>
      </table>
      <p class="submit">
        <input type="submit" value="Import Now" class="button button-primary">
      </p>
    </form>
  </div>
</div>

<script>
function hlTab(e, id) {
  e.preventDefault();
  document.querySelectorAll('.hl-tab-panel').forEach(function(p){ p.style.display = 'none'; });
  document.querySelectorAll('#hl-ie-tabs .nav-tab').forEach(function(t){ t.classList.remove('nav-tab-active'); });
  document.getElementById(id).style.display = 'block';
  e.target.classList.add('nav-tab-active');
}

// Auto-open relevant tab based on the current message.
<?php if ( in_array( $hl_msg, array( 'imported', 'no_file', 'bad_type', 'bad_json', 'bad_csv' ) ) ) : ?>
document.addEventListener('DOMContentLoaded', function(){
  hlTab({ preventDefault: function(){}, target: document.querySelectorAll('#hl-ie-tabs .nav-tab')[1] }, 'hl-import');
});
<?php elseif ( in_array( $hl_msg, array( 'reset_done', 'reset_not_confirmed' ), true ) ) : ?>
document.addEventListener('DOMContentLoaded', function(){
  hlTab({ preventDefault: function(){}, target: document.querySelectorAll('#hl-ie-tabs .nav-tab')[2] }, 'hl-reset');
});
<?php endif; ?>

// ── Reset tab interactivity ───────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function(){
  var confirmBox = document.getElementById('hl-reset-confirm');
  var submitBtn  = document.getElementById('hl-reset-submit');
  var selectAll  = document.getElementById('hl-reset-select-all');
  var tableBoxes = document.querySelectorAll('.hl-reset-table-check');

  function updateSubmitState() {
    var anyTable  = Array.from(tableBoxes).some(function(cb){ return cb.checked; });
    var confirmed = confirmBox && confirmBox.checked;
    var enabled   = anyTable && confirmed;
    if (submitBtn) {
      submitBtn.disabled = !enabled;
      submitBtn.style.opacity = enabled ? '1' : '.45';
      submitBtn.style.cursor  = enabled ? 'pointer' : 'not-allowed';
    }
  }

  if (confirmBox) confirmBox.addEventListener('change', updateSubmitState);
  tableBoxes.forEach(function(cb){ cb.addEventListener('change', updateSubmitState); });

  if (selectAll) {
    selectAll.addEventListener('change', function(){
      tableBoxes.forEach(function(cb){ cb.checked = selectAll.checked; });
      updateSubmitState();
    });
  }

  // Require a final browser confirm on submit as extra protection.
  var resetForm = document.getElementById('hl-reset-form');
  if (resetForm) {
    resetForm.addEventListener('submit', function(e){
      var selected = Array.from(tableBoxes)
        .filter(function(cb){ return cb.checked; })
        .map(function(cb){ return cb.parentElement.textContent.trim(); });
      var msg = 'You are about to permanently delete all data in:\n\n'
        + selected.join('\n')
        + '\n\nThis cannot be undone. Are you absolutely sure?';
      if (!window.confirm(msg)) {
        e.preventDefault();
      }
    });
  }
});
</script>
