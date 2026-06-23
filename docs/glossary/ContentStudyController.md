# Glossario — `ContentStudyController` (1883 LOC)

`app/Controllers/ContentStudyController.php` — God-object lato **studio/lettura** (read-side dei contenuti per la pagina di studio). 16 metodi pubblici. Gemello write-side: `docs/glossary/TeacherContentController.md`. Endpoint: `docs/ROUTES.md`. Decomposizione proposta: **ADR-029**.

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
