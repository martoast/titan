

import Alpine from 'alpinejs';
import { marked } from 'marked';
import DOMPurify from 'dompurify';

// Titan bridge frame decoders + workout assembler → window.TitanBridge (used by the device bridge).
import './bridge-decode.js';

// --- Markdown rendering for the coach chat (full GFM, safely sanitised before x-html) ---
marked.setOptions({ gfm: true, breaks: true });
// Open links in a new tab; never leak the referrer.
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
    if (node.tagName === 'A' && node.getAttribute('href')) {
        node.setAttribute('target', '_blank');
        node.setAttribute('rel', 'noopener noreferrer');
    }
    if (node.tagName === 'IMG') {
        node.setAttribute('loading', 'lazy');
    }
});
window.renderMarkdown = (text) => DOMPurify.sanitize(
    marked.parse(String(text ?? ''), { async: false }),
    { ADD_ATTR: ['target', 'rel', 'loading'] },
);

window.Alpine = Alpine;

Alpine.start();
