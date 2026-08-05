<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
start_secure_session();
$userId = require_login();
$db = get_db();

$type = $_GET['type'] ?? 'goods';
if (!in_array($type, ['goods', 'job', 'lost_found', 'housing'], true)) {
    $type = 'goods';
}
$titles = ['goods' => 'Sell an item', 'job' => 'Post a job', 'lost_found' => 'Report lost/found', 'housing' => 'List a property'];

$pageTitle = $titles[$type];
require __DIR__ . '/../../includes/layout_top.php';
?>
<div class="page-header"><h1><?= e($titles[$type]) ?></h1></div>

<form class="card" id="listing-form" style="padding: var(--space-5); max-width: 560px;" enctype="multipart/form-data">
  <div id="form-message" class="form-message"></div>
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="type" value="<?= e($type) ?>">

  <div class="field">
    <label for="title">Title</label>
    <input id="title" name="title" type="text" required maxlength="140">
  </div>

  <div class="field">
    <label for="description">Description</label>
    <textarea id="description" name="description" rows="4" maxlength="2000"></textarea>
  </div>

  <?php if ($type !== 'lost_found'): ?>
  <div class="field">
    <label for="price">Price <?= $type === 'job' ? '(leave blank if using salary range below)' : '' ?></label>
    <input id="price" name="price" type="number" step="0.01" min="0">
  </div>
  <?php endif; ?>

  <div class="field">
    <label for="location">Location</label>
    <input id="location" name="location" type="text" maxlength="140">
  </div>

  <?php if ($type === 'job'): ?>
    <div class="field-row">
      <div class="field">
        <label for="employment_type">Employment type</label>
        <select id="employment_type" name="employment_type">
          <option value="full_time">Full-time</option>
          <option value="part_time">Part-time</option>
          <option value="contract">Contract</option>
        </select>
      </div>
      <div class="field"><label for="company_name">Company</label><input id="company_name" name="company_name" type="text" maxlength="140"></div>
    </div>
    <div class="field-row">
      <div class="field"><label for="salary_min">Salary min</label><input id="salary_min" name="salary_min" type="number"></div>
      <div class="field"><label for="salary_max">Salary max</label><input id="salary_max" name="salary_max" type="number"></div>
    </div>
  <?php elseif ($type === 'housing'): ?>
    <div class="field-row">
      <div class="field">
        <label for="listing_purpose">For</label>
        <select id="listing_purpose" name="listing_purpose">
          <option value="rent">Rent</option>
          <option value="sale">Sale</option>
        </select>
      </div>
      <div class="field"><label for="bedrooms">Bedrooms</label><input id="bedrooms" name="bedrooms" type="number" min="0"></div>
      <div class="field"><label for="bathrooms">Bathrooms</label><input id="bathrooms" name="bathrooms" type="number" min="0"></div>
    </div>
    <div class="field"><label for="lease_term">Lease term</label><input id="lease_term" name="lease_term" type="text" maxlength="80"></div>
  <?php elseif ($type === 'lost_found'): ?>
    <div class="field-row">
      <div class="field">
        <label for="report_type">Type</label>
        <select id="report_type" name="report_type">
          <option value="lost">Lost</option>
          <option value="found">Found</option>
        </select>
      </div>
      <div class="field"><label for="last_seen_at">Date last seen</label><input id="last_seen_at" name="last_seen_at" type="date"></div>
    </div>
    <div class="field"><label for="last_seen_location">Last seen location</label><input id="last_seen_location" name="last_seen_location" type="text" maxlength="140"></div>
  <?php endif; ?>

  <div class="field">
    <label for="images">Photos (up to 6)</label>
    <input id="images" name="images[]" type="file" accept="image/png, image/jpeg, image/webp" multiple>
    <p class="field-hint">Photo metadata (location, device info) is stripped automatically.</p>
  </div>

  <button type="submit" class="btn-primary">Publish</button>
</form>

<script src="<?= asset_url('/assets/js/listing_form.js') ?>"></script>
<?php require __DIR__ . '/../../includes/layout_bottom.php'; ?>
