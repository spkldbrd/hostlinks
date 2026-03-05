<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Notification messages
$hl_msg = isset( $_GET['hl_msg'] ) ? sanitize_key( $_GET['hl_msg'] ) : '';
$messages = array(
	'imported' => sprintf(
		'<div class="notice notice-success"><p>Import complete: <strong>%d</strong> records imported, <strong>%d</strong> skipped (duplicates).</p></div>',
		intval( $_GET['hl_imported'] ?? 0 ),
		intval( $_GET['hl_skipped'] ?? 0 )
	),
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
            <p class="description">Exports all active events, types, marketers, and instructors as a single <code>.json</code> file.</p>
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
// Open Import tab automatically if there's an import message
<?php if ( in_array( $hl_msg, array( 'imported', 'no_file', 'bad_type', 'bad_json', 'bad_csv' ) ) ) : ?>
document.addEventListener('DOMContentLoaded', function(){
  hlTab({ preventDefault: function(){}, target: document.querySelectorAll('#hl-ie-tabs .nav-tab')[1] }, 'hl-import');
});
<?php endif; ?>
</script>
