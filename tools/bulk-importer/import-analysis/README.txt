Edutek edited CSV validator - prototype v0.2

Run with Windows PowerShell 5.1 or newer:
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\Test-EdutekImportPlan.ps1 -ManifestPath <original manifest.json> -PlanPath <edited import-plan.csv>

Does not copy, delete, index, modify the plan, or approve execution.
All original rows must remain; use include=0 to exclude. Select rows with include=1 and action=COPY or KEEP_BOTH. For flagged rows, duplicate_decision must explicitly be COPY or KEEP_BOTH. Existing destinations are always blocked regardless of decision.
Source identity fields, duplicate evidence, match_basis and source_bytes are immutable. Categories must match destination hierarchy. Excluded destinations are not validated.
CSV cells escaped by the analyzer are decoded once for mutable path fields; manifest keeps exact immutable values.
Source files added, removed or changed by size/time since analysis block validation.
Reports go into a unique validation subdirectory alongside the manifest. Exit 0 means preview valid, NOT approved; exit 2 means validation failed. Preflight errors can exit 1.
SHA256 hashes identify the manifest and CSV revisions only, not media contents. They are not an authorization signature.
Limitations: trusted operator-local manifests only, no browser/server authorization, no approval/executor, no content hashing, no companion consistency, no free-space guarantee, no cross-phase filesystem locks. Executor must recheck all metadata and paths immediately before writes.
Testing: package generated and verified in the assistant environment. PowerShell runtime testing must be performed on the Beelink.
