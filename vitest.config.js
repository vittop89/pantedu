/** Vitest config — unit tests JS pure modules. */
import { defineConfig } from "vitest/config";

export default defineConfig({
    test: {
        environment: "happy-dom",
        include: ["tests/js-unit/**/*.test.js"],
        globals: false,
        coverage: {
            reporter: ["text", "html"],
            include: ["js/modules/editor/**/*.js"],
            exclude: ["js/modules/editor/tex-dropdown/**"], // dialog UI testing in E2E
        },
    },
});
