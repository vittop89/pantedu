// Entry Vite di views/area_docente/categorie.php — fino al 2026-09-05 uno
// <script type="module"> inline aspettava window.FM.CategoryManager con un
// polling; qui il modulo si importa direttamente.
import { CategoryManager } from "../modules/features/category-manager.js";

const el = document.getElementById("fm-cat-manager");
if (el) CategoryManager.mount(el);
