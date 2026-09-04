/** Unit smoke tests — ConflictResolver strategy pattern. */
import { describe, test, expect, vi } from "vitest";
import {
    silentRetryStrategy,
    interactivePromptStrategy,
    resolveByMode,
} from "../../js/modules/editor/conflict-resolver.js";

describe("silentRetryStrategy", () => {
    test("ritorna retry con actual quando version disponibile", () => {
        const r = silentRetryStrategy.resolve({ url: "/x", body: {}, actual: 42 });
        expect(r.action).toBe("retry");
        expect(r.ifMatchVersion).toBe(42);
    });

    test("ritorna abort se actual è null", () => {
        const r = silentRetryStrategy.resolve({ url: "/x", body: {}, actual: null });
        expect(r.action).toBe("abort");
        expect(r.reason).toBe("no-actual-version");
    });
});

describe("interactivePromptStrategy", () => {
    test("user confirms (overwrite) → retry", () => {
        const promptFn = vi.fn(() => true);
        const strat = interactivePromptStrategy({ promptFn });
        const r = strat.resolve({ url: "/x", body: {}, actual: 7 });
        expect(r.action).toBe("retry");
        expect(r.ifMatchVersion).toBe(7);
        expect(promptFn).toHaveBeenCalledOnce();
    });

    test("user cancels (reload) → reload action", () => {
        const promptFn = vi.fn(() => false);
        const strat = interactivePromptStrategy({ promptFn });
        const r = strat.resolve({ url: "/x", body: {}, actual: 7 });
        expect(r.action).toBe("reload");
    });

    test("no actual version → abort senza chiamare prompt", () => {
        const promptFn = vi.fn();
        const strat = interactivePromptStrategy({ promptFn });
        const r = strat.resolve({ url: "/x", body: {}, actual: null });
        expect(r.action).toBe("abort");
        expect(promptFn).not.toHaveBeenCalled();
    });

    test("custom message viene passato a promptFn", () => {
        const promptFn = vi.fn(() => true);
        const strat = interactivePromptStrategy({ promptFn, message: "custom msg" });
        strat.resolve({ url: "/x", body: {}, actual: 1 });
        expect(promptFn).toHaveBeenCalledWith("custom msg");
    });
});

describe("resolveByMode", () => {
    test("silent=true → silentRetryStrategy", () => {
        const r = resolveByMode(true, { url: "/x", body: {}, actual: 99 });
        expect(r.action).toBe("retry");
        expect(r.ifMatchVersion).toBe(99);
    });

    test("silent=false → interactive (con confirm dom default)", () => {
        // happy-dom fornisce window.confirm. Mock per evitare popup.
        globalThis.window = { confirm: () => true };
        const r = resolveByMode(false, { url: "/x", body: {}, actual: 5 });
        expect(r.action).toBe("retry");
        expect(r.ifMatchVersion).toBe(5);
    });
});
