/**
 * PostCSS pipeline — Phase Roadmap 7.
 *
 * Plugins:
 *   - postcss-import: resolve @import inline so cssnano can dedupe
 *   - postcss-preset-env: modern CSS → broad browser support
 *   - autoprefixer: vendor prefixes (covered by preset-env stage 3
 *     but kept explicit for clarity)
 *   - cssnano: minify (production only)
 *
 * Purge intentionally NOT enabled here: rischio rimozione regole
 * usate da PHP-generated class names (es. .fm-status[data-role=…]).
 * Run separato `npm run css:purge` con safelist esplicita.
 *
 * Browsers target: see .browserslistrc.
 */
export default ({ env }) => ({
    plugins: {
        "postcss-import": {
            path: ["css/", "css/modules/"],
        },
        "postcss-preset-env": {
            stage: 2,
            features: {
                "nesting-rules": true,
                "custom-media-queries": true,
                "custom-properties": false, // we use them at runtime
                "logical-properties-and-values": true,
                "cascade-layers": false, // already supported by target browsers
            },
            autoprefixer: { grid: "no-autoplace" },
        },
        ...(env === "production"
            ? {
                  cssnano: {
                      preset: [
                          "default",
                          {
                              discardComments: { removeAll: true },
                              normalizeWhitespace: true,
                              mergeRules: true,
                          },
                      ],
                  },
              }
            : {}),
    },
});
