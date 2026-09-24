/**
 * PathFileVerExtractor — class orphan estratta da functions-mod.js.
 * Stesso nome della classe dentro Utils.PathFileVerExtractor — qui
 * la versione top-level standalone usata dal legacy code.
 */

export class PathFileVerExtractor {
  constructor(path) {
    this.path = path;
    this.filename = this.getFileName();
    this.prefix = this.extractPrefix();
    this.word = this.extractWord();
  }
  // Metodo per ottenere il percorso fino alla cartella del file
  getFolderPath() {
    let lastSeparatorIndex = this.path.lastIndexOf("/");
    if (lastSeparatorIndex === -1) {
      lastSeparatorIndex = this.path.lastIndexOf("\\");
    }
    if (lastSeparatorIndex !== -1) {
      return this.path.substring(0, lastSeparatorIndex);
    } else {
      return ""; // Restituisce una stringa vuota se non viene trovato alcun separatore
    }
  }
  getFileName() {
    // let segments = this.path.split('/');
    // Ottieni l'ultimo elemento dell'array
    const filename = this.path.split("/").pop();
    return filename;
  }
  fileExtension() {
    return this.path.split(".").pop();
  }
  // Metodo per estrarre il prefisso
  extractPrefix() {
    const underscoreIndex = this.filename.indexOf("_");
    return this.filename.substring(underscoreIndex + 1, underscoreIndex + 4);
    // return this.filename.substring(0, 3);
  }

  // Metodo per estrarre la parola tra il trattino '-' e il trattino basso '_'
  extractWord() {
    const dashIndex = this.filename.indexOf("-");
    const underscoreIndex = this.filename.indexOf("-", dashIndex + 1);
    return this.filename.substring(dashIndex + 1, underscoreIndex);
  }

  // Metodo per ottenere il prefisso
  getPrefix() {
    return this.prefix;
  }

  // Metodo per ottenere il prefisso e la parola combinati
  getFileVer() {
    return `${this.prefix  }-${  this.word  }-ver`;
  }
  // Metodo per generare il percorso di verifica
  verPath() {
    const filename_ver = this.getFileVer();
    const dirname_ver = this.getPrefix();
    const fileExtension = this.fileExtension();
    const verPath = `/verifiche/php/${  dirname_ver  }/${  filename_ver  }.${  fileExtension}`;
    // console.log("Il file attuale è in: " + this.path);
    // console.log("Il prefisso è: " + this.prefix + "\nLa parola è: " + this.word);
    // console.log("il file name è: " + this.filename);
    // console.log("Il file verifiche è in: " + verPath);
    // return verPath;
    return decodeURIComponent(verPath);
  }
}

window.FM = window.FM || {};
window.FM.PathFileVerExtractorClass = PathFileVerExtractor;
window.PathFileVerExtractor         = PathFileVerExtractor;
