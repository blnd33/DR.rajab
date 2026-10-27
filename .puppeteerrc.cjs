/**
 * PDFs (Phase 5) use the server's own Chrome / Chromium through Browsershot, so npm install
 * never downloads Puppeteer's bundled browser. Set PDF_CHROME_PATH if Chrome is not found.
 */
module.exports = {
    skipDownload: true,
};
