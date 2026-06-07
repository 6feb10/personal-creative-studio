<!-- PWA -->
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#7c5cff">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="DreamStudio">
<link rel="apple-touch-icon" href="/icons/icon-192.png">
<script>
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/sw.js').catch(() => {});
}
</script>
