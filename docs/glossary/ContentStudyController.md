# Glossario — `ContentStudyController` (804 LOC al 2026-09-04; era 1883)

`app/Controllers/ContentStudyController.php` — controller lato **studio/lettura** (read-side dei contenuti per la pagina di studio). Gemello write-side: `docs/glossary/TeacherContentController.md`. Endpoint: `docs/ROUTES.md`. Decomposizione: **ADR-029**, completata per lo studio con la revisione architetturale 2026-09 (intervento P5).

> **Stato 2026-09-04.** Il controller è sceso da 1.852 a 804 righe con due estrazioni:
>
> - `app/Services/Study/StudyPageRenderer.php` (≈890 righe) — tutto il rendering HTML: `renderTopicsHtml`, `renderTopicHtml` (mappa drawio con alternativa testuale WCAG, documento custom, contratto esercizi/verifiche), `wrapInShell`. Riceve il repository dei contenuti e il permesso di modifica del visitatore; il controller lo raggiunge con `renderer()`.
> - `app/Controllers/PublicStudyController.php` (≈220 righe) — le tre rotte pubbliche (`/public/studio/{id}`, `/api/public/study/topics.json`, `/api/public/study/content.json`): `publicView`, `publicTopicsJson`, `publicContentJson`. La regola di visibilità pubblica (super-admin docente + published + sezione publish_public) sta in `app/Services/Study/PublicContentPolicy.php`, usata anche da `contentSingleJson`.
>
> Restano qui: pagine e JSON autenticati (`topicsPage`, `topicPage`, `topicsJson`, `contentJson`, `contentSingleJson`, `relatedVerificaHtml`), i filtri di scope (`scopedFilters`, `viewerContext`, `applyAclFilter`) e gli helper. Le tabelle sotto riflettono lo stato del 2026-06-11 e restano come mappa storica dei metodi; i metodi header/fonti/origini erano già usciti in `StudyHeaderController` e `StudySourcesController`.

> **NB sul nome** (causa di confusione): `ContentStudyController` è il **read-side** (cosa uno studente/studio vede), non "il docente che crea". Il write-side è `TeacherContentController`.

## Metodi per area (seam proposto in ADR-029)

### Topics & contenuti (pagine + JSON) → `StudyContentController`
| Metodo | L# | Cosa fa |
|--------|----|---------|
| `topicsPage` | 41 | Pagina lista topic |
| `topicPage` | 68 | Pagina singolo topic |
| `topicsJson` | 116 | JSON lista topic |
| `contentJson` | 140 | JSON contenuti |
| `contentSingleJson` | 960 | JSON singolo contenuto |

### Header pagina → `StudyHeaderController`
| Metodo | L# | Cosa fa |
|--------|----|---------|
| `headerPageJson` | 521 | Header pagina (docente) |
| `headerPageStudentJson` | 550 | Header pagina (studente) |
| `headerPageSave` | 630 | Salva header pagina |

### Fonti & origini → `StudySourcesController`
| Metodo | L# | Cosa fa |
|--------|----|---------|
| `sourcesCommonJson` | 395 | Fonti comuni |
| `originsJson` | 673 | Origini |
| `sourcesSave` | 720 | Salva fonti |
| `sourcesRegistrySave` | 797 | Salva registry fonti |
| `checkedOriginsJson` | 857 | Origini selezionate (get) |
| `checkedOriginsSave` | 884 | Origini selezionate (save) |
| `sourcesRegistryJson` | 933 | Registry fonti (get) |

### Verifiche correlate → `StudyVerificaController`
| Metodo | L# | Cosa fa |
|--------|----|---------|
| `relatedVerificaHtml` | 256 | HTML verifica correlata |

Line# riferiti allo stato 2026-06-11; rigenerare con `grep -nE 'public function ' app/Controllers/ContentStudyController.php`.
