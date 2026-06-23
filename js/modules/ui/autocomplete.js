/**
 * Autocomplete / combobox riusabile e accessibile (WCAG 2.2 AA).
 *
 * Sostituisce il <datalist> nativo (non stilabile, non a tema dark) con un
 * listbox custom navigabile da tastiera. Theme-agnostic: lo stile vive nelle
 * classi BEM `.fm-ac*` (il chiamante fornisce il CSS, di norma via tokens).
 *
 * Pattern ARIA: input role=combobox + ul role=listbox + li role=option,
 * aria-expanded / aria-activedescendant / aria-controls.
 *
 * Uso:
 *   import { attachAutocomplete } from "/js/modules/ui/autocomplete.js";
 *   const ac = attachAutocomplete(inputEl, {
 *       items: () => allInstitutes,           // array o getter
 *       getLabel: i => i.name,                // testo principale
 *       getMeta:  i => `${i.code} · ${i.city}`,
 *       getNote:  i => linked.has(i.code) ? "✓ già collegato" : "",
 *       onSelect: i => { ... },               // scelta confermata
 *   });
 *   ac.refresh();  // ridisegna se i dati cambiano mentre è aperto
 *
 * @param {HTMLInputElement} input
 * @param {{
 *   items: (Array|function():Array),
 *   getLabel: function(any):string,
 *   getMeta?: function(any):string,
 *   getNote?: function(any):string,
 *   onSelect: function(any):void,
 *   minChars?: number,
 *   maxResults?: number,
 *   match?: function(any, string):boolean,
 * }} opts
 */
export function attachAutocomplete(input, opts) {
    const {
        items,
        getLabel,
        getMeta = () => "",
        getNote = () => "",
        onSelect,
        minChars = 1,
        maxResults = 60,
        match,
    } = opts;

    const getItems = typeof items === "function" ? items : () => items;
    const uid = "fm-ac-" + Math.random().toString(36).slice(2, 9);

    // Wrappa l'input in un contenitore relativo così il listbox si posiziona
    // sotto, largo quanto l'input.
    const wrap = document.createElement("div");
    wrap.className = "fm-ac";
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    const list = document.createElement("ul");
    list.className = "fm-ac__list";
    list.id = uid;
    list.setAttribute("role", "listbox");
    list.hidden = true;
    wrap.appendChild(list);

    input.setAttribute("role", "combobox");
    input.setAttribute("aria-autocomplete", "list");
    input.setAttribute("aria-expanded", "false");
    input.setAttribute("aria-controls", uid);

    let current = [];     // item filtrati visibili
    let activeIdx = -1;

    const defaultMatch = (item, q) => {
        const hay = (getLabel(item) + " " + getMeta(item)).toLowerCase();
        return q.split(/\s+/).filter(Boolean).every(tok => hay.includes(tok));
    };
    const doMatch = match || defaultMatch;

    function close() {
        list.hidden = true;
        input.setAttribute("aria-expanded", "false");
        input.removeAttribute("aria-activedescendant");
        activeIdx = -1;
    }

    function open() {
        if (!list.children.length) return;
        list.hidden = false;
        input.setAttribute("aria-expanded", "true");
    }

    function setActive(idx) {
        const opts = list.querySelectorAll(".fm-ac__item");
        opts.forEach(o => o.classList.remove("fm-ac__item--active"));
        if (idx < 0 || idx >= opts.length) { activeIdx = -1; input.removeAttribute("aria-activedescendant"); return; }
        activeIdx = idx;
        const el = opts[idx];
        el.classList.add("fm-ac__item--active");
        el.setAttribute("aria-selected", "true");
        input.setAttribute("aria-activedescendant", el.id);
        el.scrollIntoView({ block: "nearest" });
    }

    function pick(item) {
        input.value = getLabel(item);
        close();
        onSelect(item);
    }

    function render() {
        const q = input.value.trim().toLowerCase();
        list.innerHTML = "";
        current = [];
        if (q.length < minChars) { close(); return; }
        const all = getItems() || [];
        for (const item of all) {
            if (!doMatch(item, q)) continue;
            current.push(item);
            if (current.length > maxResults) break;
        }
        if (!current.length) { close(); return; }
        current.forEach((item, i) => {
            const li = document.createElement("li");
            li.className = "fm-ac__item";
            li.id = uid + "-opt-" + i;
            li.setAttribute("role", "option");

            const label = document.createElement("span");
            label.className = "fm-ac__label";
            label.textContent = getLabel(item);
            li.appendChild(label);

            const meta = getMeta(item);
            const note = getNote(item);
            if (meta || note) {
                const sub = document.createElement("span");
                sub.className = "fm-ac__meta";
                sub.textContent = meta;
                if (note) {
                    const n = document.createElement("span");
                    n.className = "fm-ac__note";
                    n.textContent = note;
                    sub.appendChild(document.createTextNode(meta ? "  " : ""));
                    sub.appendChild(n);
                }
                li.appendChild(sub);
            }
            // mousedown (non click) per evitare il blur dell'input prima della scelta.
            li.addEventListener("mousedown", (e) => { e.preventDefault(); pick(item); });
            list.appendChild(li);
        });
        open();
        setActive(-1);
    }

    input.addEventListener("input", render);
    input.addEventListener("focus", () => { if (input.value.trim().length >= minChars) render(); });
    input.addEventListener("blur", () => setTimeout(close, 120));
    input.addEventListener("keydown", (e) => {
        if (list.hidden && (e.key === "ArrowDown" || e.key === "ArrowUp")) { render(); return; }
        switch (e.key) {
            case "ArrowDown": e.preventDefault(); setActive(Math.min(activeIdx + 1, current.length - 1)); break;
            case "ArrowUp":   e.preventDefault(); setActive(Math.max(activeIdx - 1, 0)); break;
            case "Enter":
                if (!list.hidden && activeIdx >= 0) { e.preventDefault(); pick(current[activeIdx]); }
                break;
            case "Escape":    close(); break;
            case "Tab":       close(); break;
        }
    });

    return {
        refresh: () => { if (!list.hidden) render(); },
        close,
        destroy: () => { input.removeEventListener("input", render); list.remove(); },
    };
}
