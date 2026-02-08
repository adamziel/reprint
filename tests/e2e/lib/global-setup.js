/**
 * Vitest globalSetup — provisions all test sites once before any test file runs.
 */
export async function setup() {
    await import('./provision-all.js');
}
