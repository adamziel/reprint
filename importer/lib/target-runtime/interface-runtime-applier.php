<?php
/**
 * Interface for runtime appliers.
 *
 * A runtime applier takes a RuntimeManifest and writes the configuration
 * files needed to run the imported site on a specific server platform.
 *
 * Use RuntimeAppliers::for_runtime() to instantiate one by name.
 */
interface RuntimeApplier
{
    /**
     * Apply a manifest to the target docroot.
     *
     * @param RuntimeManifest $manifest   The manifest to apply.
     * @param string          $docroot    Absolute path to the site docroot.
     * @param string          $output_dir Absolute path to the output directory.
     * @param array           $options    Runtime-specific options (e.g. host, port,
     *                                    wordpress_index).
     * @return string[] Human-readable summary lines (printed to the user).
     */
    public function apply(RuntimeManifest $manifest, string $docroot, string $output_dir, array $options = []): array;
}
