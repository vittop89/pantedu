# G26 — jQuery → Vanilla JS conversion patterns

> Reference cheat-sheet per la migrazione G26. Usare durante refactor di
> ogni file. Pattern testati su Edge/Chrome/Firefox/Safari modern.

## Selectors

| jQuery | Vanilla |
|--------|---------|
| `$('#id')` | `document.getElementById('id')` |
| `$('.class')` | `document.querySelectorAll('.class')` (NodeList) |
| `$('.class').first()` | `document.querySelector('.class')` |
| `$('.class', context)` | `context.querySelectorAll('.class')` |
| `$('.class:first')` | `document.querySelector('.class')` |
| `$('.class:last')` | last of `querySelectorAll('.class')` |
| `$('.class').eq(N)` | `querySelectorAll('.class')[N]` |
| `$(this)` (inside event) | `event.currentTarget` (or `event.target`) |
| `$(this)` (inside `.each`) | the callback param (`el`) |

## Traversal

| jQuery | Vanilla |
|--------|---------|
| `$el.find('.x')` | `el.querySelectorAll('.x')` |
| `$el.find('.x').first()` | `el.querySelector('.x')` |
| `$el.children()` | `el.children` (HTMLCollection) |
| `$el.children('.x')` | `[...el.children].filter(c => c.matches('.x'))` |
| `$el.parent()` | `el.parentElement` |
| `$el.parents('.x')` | walk parents + check matches (see helper) |
| `$el.closest('.x')` | `el.closest('.x')` ✅ native |
| `$el.siblings()` | `[...el.parentElement.children].filter(c => c !== el)` |
| `$el.siblings('.x')` | filter as above + `.matches('.x')` |
| `$el.next()` | `el.nextElementSibling` |
| `$el.prev()` | `el.previousElementSibling` |
| `$el.first()` | `el[0]` or first of collection |
| `$el.last()` | `el[el.length - 1]` |

## Iteration

| jQuery | Vanilla |
|--------|---------|
| `$('.x').each((i, el) => ...)` | `document.querySelectorAll('.x').forEach((el, i) => ...)` |
| `$('.x').map(fn)` | `[...document.querySelectorAll('.x')].map(fn)` |
| `$.each(array, fn)` | `array.forEach(fn)` |

## Attributes / properties

| jQuery | Vanilla |
|--------|---------|
| `$el.attr('foo')` | `el.getAttribute('foo')` |
| `$el.attr('foo', 'bar')` | `el.setAttribute('foo', 'bar')` |
| `$el.removeAttr('foo')` | `el.removeAttribute('foo')` |
| `$el.prop('checked')` | `el.checked` |
| `$el.prop('disabled', true)` | `el.disabled = true` |
| `$el.data('key')` | `el.dataset.key` |
| `$el.data('key', val)` | `el.dataset.key = val` |
| `$el.val()` | `el.value` |
| `$el.val('x')` | `el.value = 'x'` |
| `$el.is(':checked')` | `el.checked` |
| `$el.is(':visible')` | `el.offsetParent !== null` |
| `$el.is('.x')` | `el.matches('.x')` |

## Content

| jQuery | Vanilla |
|--------|---------|
| `$el.html()` | `el.innerHTML` |
| `$el.html('x')` | `el.innerHTML = 'x'` |
| `$el.text()` | `el.textContent` |
| `$el.text('x')` | `el.textContent = 'x'` |
| `$el.empty()` | `el.replaceChildren()` |
| `$el.append('<div>')` | `el.insertAdjacentHTML('beforeend', '<div>')` |
| `$el.append(child)` | `el.appendChild(child)` |
| `$el.prepend(child)` | `el.prepend(child)` |
| `$el.before(content)` | `el.before(content)` ✅ native |
| `$el.after(content)` | `el.after(content)` ✅ native |
| `$el.wrap('<div>')` | manual: parent.insertBefore wrap; wrap.appendChild(el) |
| `$el.unwrap()` | `el.parentElement.replaceWith(...el.parentElement.children)` |
| `$el.remove()` | `el.remove()` ✅ native |
| `$el.detach()` | `el.remove()` (no jQuery data preservation) |
| `$el.replaceWith(x)` | `el.replaceWith(x)` ✅ native |

## CSS / classes

| jQuery | Vanilla |
|--------|---------|
| `$el.addClass('x')` | `el.classList.add('x')` |
| `$el.addClass('a b')` | `el.classList.add('a', 'b')` |
| `$el.removeClass('x')` | `el.classList.remove('x')` |
| `$el.toggleClass('x')` | `el.classList.toggle('x')` |
| `$el.hasClass('x')` | `el.classList.contains('x')` |
| `$el.css('color')` | `getComputedStyle(el).color` |
| `$el.css('color', 'red')` | `el.style.color = 'red'` |
| `$el.css({a:1, b:2})` | `Object.assign(el.style, {a:1, b:2})` |
| `$el.hide()` | `el.style.display = 'none'` |
| `$el.show()` | `el.style.display = ''` (or original) |
| `$el.toggle()` | manual `el.style.display = X ? '' : 'none'` |
| `$el.width()` | `el.offsetWidth` (with border) or `clientWidth` (without) |
| `$el.height()` | `el.offsetHeight` / `clientHeight` |
| `$el.offset()` | `el.getBoundingClientRect()` + `window.scrollX/Y` |

## Events

| jQuery | Vanilla |
|--------|---------|
| `$el.on('click', fn)` | `el.addEventListener('click', fn)` |
| `$el.off('click', fn)` | `el.removeEventListener('click', fn)` |
| `$el.off('click')` | track listener + remove (no native bulk off) |
| `$el.click(fn)` | `el.addEventListener('click', fn)` |
| `$el.trigger('click')` | `el.click()` or `el.dispatchEvent(new Event('click'))` |
| `$el.on('click', '.child', fn)` (delegation) | see EVENT DELEGATION pattern below |
| `$(document).ready(fn)` | `document.addEventListener('DOMContentLoaded', fn)` or top-level `fn()` if defer/module |

### Event delegation pattern

```js
// jQuery
$(document).on('click', '.btn-foo', function(e) {
  console.log($(this).text());
});

// Vanilla
document.addEventListener('click', (e) => {
  const target = e.target.closest('.btn-foo');
  if (!target) return;
  console.log(target.textContent);
});
```

## AJAX

### `$.ajax()` → `fetch()`

```js
// jQuery
$.ajax({
  url: '/api/foo',
  type: 'POST',
  data: { x: 1, y: 2 },
  dataType: 'json',
  success: function(response) { console.log(response); },
  error: function(xhr, status, err) { console.error(err); },
});

// Vanilla
try {
  const res = await fetch('/api/foo', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ x: 1, y: 2 }).toString(),
    credentials: 'same-origin',
  });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const response = await res.json();
  console.log(response);
} catch (err) {
  console.error(err);
}
```

### `$.getJSON()` → `fetch().json()`

```js
// jQuery
$.getJSON('/api/data.json', function(data) { /* ... */ });

// Vanilla
fetch('/api/data.json').then(r => r.json()).then(data => { /* ... */ });
// Or async:
const data = await (await fetch('/api/data.json')).json();
```

### `$.get()` → `fetch()`

```js
// jQuery
$.get('/foo', function(html) { $('#out').html(html); });

// Vanilla
const html = await (await fetch('/foo')).text();
document.getElementById('out').innerHTML = html;
```

## Forms

| jQuery | Vanilla |
|--------|---------|
| `$form.serialize()` | `new URLSearchParams(new FormData(form)).toString()` |
| `$form.serializeArray()` | iterate FormData entries |
| `$('input[name=x]').val()` | `form.elements.x.value` or `form.querySelector('[name=x]').value` |
| `$form.find(':checked')` | `form.querySelectorAll(':checked')` |
| `$el.focus()` | `el.focus()` ✅ native |
| `$el.blur()` | `el.blur()` ✅ native |

## Animation

| jQuery | Vanilla |
|--------|---------|
| `$el.fadeIn()` | CSS `transition: opacity` + `el.style.opacity = 1` |
| `$el.fadeOut()` | CSS + `el.style.opacity = 0` + `transitionend` |
| `$el.slideDown()` | CSS `transition: height` (requires set max-height) |
| `$el.animate(...)` | `el.animate([...], opts)` (Web Animations API) |

Preferire **CSS transitions** dove possibile (più performante).

## Document ready / DOMContentLoaded

```js
// jQuery
$(document).ready(function() { /* ... */ });
// or shorthand:
$(function() { /* ... */ });

// Vanilla
document.addEventListener('DOMContentLoaded', () => { /* ... */ });

// If script is defer or type=module:
// just run code at top-level, DOM is ready
```

## ESM module loading

```js
// Old (script tag): jQuery is global, no import
$.fn.myPlugin = function() { ... };

// New (ESM): exports
export function myComponent() { ... }
import { myComponent } from './my-component.js';
```

## Helpers per pattern complessi

### parents() — jQuery `.parents('.x')`

```js
function parentsMatching(el, selector) {
  const result = [];
  let cur = el.parentElement;
  while (cur) {
    if (cur.matches(selector)) result.push(cur);
    cur = cur.parentElement;
  }
  return result;
}
```

### siblings() — jQuery `.siblings('.x')`

```js
function siblings(el, selector = null) {
  if (!el.parentElement) return [];
  return [...el.parentElement.children].filter(c =>
    c !== el && (selector ? c.matches(selector) : true)
  );
}
```

### show()/hide() — preserva display original

```js
function showEl(el) {
  if (el.dataset.fmDisplayOrig) el.style.display = el.dataset.fmDisplayOrig;
  else el.style.display = '';
  delete el.dataset.fmDisplayOrig;
}
function hideEl(el) {
  const current = getComputedStyle(el).display;
  if (current !== 'none') el.dataset.fmDisplayOrig = current;
  el.style.display = 'none';
}
```

### serialize() — FormData → URLSearchParams

```js
function serializeForm(form) {
  return new URLSearchParams(new FormData(form)).toString();
}
```

### offset() — page-relative position

```js
function offset(el) {
  const rect = el.getBoundingClientRect();
  return {
    top: rect.top + window.scrollY,
    left: rect.left + window.scrollX,
  };
}
```

## Gotchas

1. **`querySelectorAll` returns NodeList**, not Array. Use `[...nodes]` or
   `Array.from(nodes)` for `.map()` / `.filter()`.
   Note: NodeList has `.forEach` natively (no spread needed).

2. **`getComputedStyle` returns CSS values as STRINGS** (e.g., `"100px"`).
   Use `parseFloat()` if you need number.

3. **`.classList.toggle('x', condition)`** is more concise than `if (cond)
   el.classList.add('x') else .remove()`.

4. **Event delegation**: `e.target` may be a child of the actual button.
   Always use `e.target.closest('.expected-selector')` for safety.

5. **`fetch()` doesn't throw on 4xx/5xx** — only on network failure. Always
   check `res.ok`.

6. **`fetch()` doesn't send cookies by default** for cross-origin. For
   same-origin POSTs, add `credentials: 'same-origin'`.

7. **`new FormData(form)`** captures the form state at construction time.
   Don't mutate form between FormData creation and serialize.

8. **`element.remove()`** is fully supported in modern browsers (IE11
   needed `.parentNode.removeChild(el)`, but we don't target IE11).

## Testing checklist post-conversion

For each file migrated:

- [ ] `npm run build` passes (vite)
- [ ] `npm test` passes if unit tests exist for the file
- [ ] Manual smoke: open browser, exercise the feature
- [ ] No console errors on page load / interaction
- [ ] Network panel: AJAX calls succeed
- [ ] Verify event handlers fire (click, change, keyboard)
- [ ] Verify DOM mutations correct (no orphan nodes, etc.)

## Resources

- MDN: https://developer.mozilla.org/ (every API documented)
- "You Might Not Need jQuery": https://youmightnotneedjquery.com/ (side-by-side)
- "Vanilla JS toolkit": https://vanillajstoolkit.com/

## See also

- Master plan: `docs/plans/G26-jquery-removal-master-plan.md`
- ESLint guard (Phase 2): `eslint.config.js`
