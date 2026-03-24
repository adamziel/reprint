<?php
/**
 * On-the-fly thumbnail generation for WordPress upload images.
 *
 * WordPress stores references to sized variants like image-768x768.jpeg in
 * post meta, but the exported site only has the original full-size file.
 * This intercepts requests for missing thumbnails inside /wp-content/uploads/,
 * generates a properly resized copy from the original using GD, caches it on
 * disk so subsequent requests are served directly, and exits before WordPress
 * boots — keeping it fast.
 *
 * This script expects WP_CONTENT_DIR to be defined by the bootstrap that
 * requires it. It reads $_SERVER['REQUEST_URI'] to detect thumbnail requests.
 */
(function() {
	if (!defined('WP_CONTENT_DIR')) return;

	$uri = $_SERVER['REQUEST_URI'] ?? '';
	// Strip query string
	$path = parse_url($uri, PHP_URL_PATH);
	if (!$path) return;

	// Only handle requests inside /wp-content/uploads/
	if (strpos($path, '/wp-content/uploads/') === false) return;

	// Only handle filenames with a -WxH thumbnail suffix
	if (!preg_match('/-(\d+)x(\d+)(\.\w+)$/', $path, $m)) return;

	$max_width  = (int) $m[1];
	$max_height = (int) $m[2];
	$ext        = strtolower($m[3]);

	// Map the URL path to a filesystem path relative to WP_CONTENT_DIR.
	// The URL contains /wp-content/uploads/... and the uploads directory
	// lives inside WP_CONTENT_DIR.
	$relative   = preg_replace('#^.*/wp-content/#', '', $path);
	$thumb_path = WP_CONTENT_DIR . '/' . $relative;

	// If the thumbnail already exists on disk, let the server handle it
	if (file_exists($thumb_path)) return;

	// Derive the original (unsized) file path
	$original_path = preg_replace('/-\d+x\d+(\.\w+)$/', '$1', $thumb_path);
	if (!file_exists($original_path)) return;

	// Load the original image with GD
	$src = null;
	switch ($ext) {
		case '.jpg':
		case '.jpeg':
			$src = @imagecreatefromjpeg($original_path);
			break;
		case '.png':
			$src = @imagecreatefrompng($original_path);
			break;
		case '.gif':
			$src = @imagecreatefromgif($original_path);
			break;
		case '.webp':
			if (function_exists('imagecreatefromwebp')) {
				$src = @imagecreatefromwebp($original_path);
			}
			break;
	}
	if (!$src) return;

	// Compute the scaled dimensions (fit inside max_width x max_height,
	// never enlarge beyond the original)
	$orig_w = imagesx($src);
	$orig_h = imagesy($src);
	$scale  = min($max_width / $orig_w, $max_height / $orig_h, 1.0);
	$new_w  = (int) round($orig_w * $scale);
	$new_h  = (int) round($orig_h * $scale);

	$dst = imagecreatetruecolor($new_w, $new_h);

	// Preserve transparency for PNG and GIF
	if ($ext === '.png') {
		imagealphablending($dst, false);
		imagesavealpha($dst, true);
	} elseif ($ext === '.gif') {
		$transparent = imagecolortransparent($src);
		if ($transparent >= 0) {
			$color = imagecolorsforindex($src, $transparent);
			$new_transparent = imagecolorallocate($dst, $color['red'], $color['green'], $color['blue']);
			imagefill($dst, 0, 0, $new_transparent);
			imagecolortransparent($dst, $new_transparent);
		}
	}

	imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h);

	// Cache the thumbnail to disk so future requests skip this code path
	$thumb_dir = dirname($thumb_path);
	if (!is_dir($thumb_dir)) {
		@mkdir($thumb_dir, 0755, true);
	}

	$content_type = 'application/octet-stream';
	switch ($ext) {
		case '.jpg':
		case '.jpeg':
			imagejpeg($dst, $thumb_path, 82);
			$content_type = 'image/jpeg';
			break;
		case '.png':
			imagepng($dst, $thumb_path);
			$content_type = 'image/png';
			break;
		case '.gif':
			imagegif($dst, $thumb_path);
			$content_type = 'image/gif';
			break;
		case '.webp':
			imagewebp($dst, $thumb_path, 82);
			$content_type = 'image/webp';
			break;
	}

	imagedestroy($src);
	imagedestroy($dst);

	// Serve the freshly generated thumbnail and stop — no need to boot WP
	header('Content-Type: ' . $content_type);
	header('Content-Length: ' . filesize($thumb_path));
	readfile($thumb_path);
	exit;
})();
