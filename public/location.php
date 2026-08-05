<?php
// Location sharing now lives inside Settings - this file just redirects
// so any old bookmarks/links keep working instead of 404ing.
header('Location: /settings.php');
exit;
