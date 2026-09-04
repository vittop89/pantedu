/**
 * DomManager (selection-manager) — estratto da functions-mod.js (Phase 9e).
 * G26.phase5.3 — migrato a vanilla JS (no jQuery).
 *
 * Note: questo file esporta `DomManager` (selezione + DOM helper).
 * Il vero "DomManager" monolite è ancora in js/modules/ui/dom-manager.js
 * (ignore list).
 */
import { asElement } from "../core/dom-utils.js";

export const DomManager = {
    saveSelection: function () {
        const selection = window.getSelection();
        if (selection.rangeCount > 0) {
            const range = selection.getRangeAt(0);
            savedRange = {
                startContainer: this.getNodePath(range.startContainer),
                startOffset: range.startOffset,
                endContainer: this.getNodePath(range.endContainer),
                endOffset: range.endOffset,
            };
        }
        return savedRange.endOffset;
    },

    restoreSelection: function () {
        const selection = window.getSelection();
        if (savedRange) {
            const range = document.createRange();
            const startNode = this.getNodeFromPath(savedRange.startContainer);
            const endNode = this.getNodeFromPath(savedRange.endContainer);
            if (startNode && endNode) {
                const startOffset = Math.min(savedRange.startOffset, startNode.nodeType === Node.TEXT_NODE ? startNode.length : startNode.childNodes.length);
                const endOffset = Math.min(savedRange.endOffset, endNode.nodeType === Node.TEXT_NODE ? endNode.length : endNode.childNodes.length);
                range.setStart(startNode, startOffset);
                range.setEnd(endNode, endOffset);
                selection.removeAllRanges();
                selection.addRange(range);
            }
        }
    },

    getNodePath: function (node) {
        const path = [];
        while (node && node.parentNode) {
            const index = Array.prototype.indexOf.call(node.parentNode.childNodes, node);
            path.unshift(index);
            node = node.parentNode;
        }
        return path;
    },

    getNodeFromPath: function (path) {
        let node = document;
        for (let i = 0; i < path.length; i++) {
            node = node.childNodes[path[i]];
            if (!node) return null;
        }
        return node;
    },

    wrapDirectTextNodesInDivs: function (editor) {
        const editorEl = asElement(editor);
        if (!editorEl) return;

        const editorHtml = editorEl.innerHTML;
        const hasTikzCode = editorHtml.includes("\\usepackage") || editorHtml.includes("\\begin{tikzpicture}");
        if (hasTikzCode) return;

        let combinedText = "";
        const newChildren = [];
        const flushCombined = () => {
            if (combinedText) {
                const div = document.createElement("div");
                div.innerHTML = combinedText;
                newChildren.push(div);
                combinedText = "";
            }
        };

        Array.from(editorEl.childNodes).forEach((node) => {
            if (node.nodeType === Node.TEXT_NODE) {
                combinedText += node.nodeValue.trim();
            } else if (node.nodeType === Node.ELEMENT_NODE && node.tagName.toLowerCase() === "br") {
                combinedText += "<br>";
            } else {
                flushCombined();
                newChildren.push(node.cloneNode(true));
            }
        });
        flushCombined();

        editorEl.replaceChildren(...newChildren);
    },

    unwrapDivs: function (editorId) {
        const editorEl = asElement(editorId);
        if (!editorEl) return;

        editorEl.querySelectorAll("div").forEach((div) => {
            const children = Array.from(div.children);
            const allDivs = children.length > 0 && children.every((c) => c.tagName === "DIV");
            if (!allDivs) return;

            // Wrap diretti text-nodes in <div>
            const directTextNodes = Array.from(div.childNodes).filter((n) => n.nodeType === Node.TEXT_NODE);
            if (directTextNodes.length > 0) {
                directTextNodes.forEach((tn) => {
                    const wrapper = document.createElement("div");
                    tn.parentNode.insertBefore(wrapper, tn);
                    wrapper.appendChild(tn);
                });
            }

            // Unwrap children: sostituisci div padre con i suoi figli
            const parent = div.parentNode;
            if (!parent) return;
            children.forEach((child) => parent.insertBefore(child, div));
            parent.removeChild(div);
        });
    },

    appendIdSuffix0: function (element) {
        const el = asElement(element);
        if (!el) return;
        if (el.id) el.id += "_add0";
        el.querySelectorAll("[id]").forEach((child) => {
            child.id += "_add0";
        });
    },
};

window.FM = window.FM || {};
window.FM.DomManager = DomManager;
window.DomManager    = DomManager;
