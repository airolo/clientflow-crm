<?php
/**
 * Closes <main> and the document shell. Also renders the confirm-dialog helper
 * used by every destructive action (delete, cancel, deactivate).
 */
?>
    </main>
</div>

<footer class="app-footer">
    <div>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?></div>
    <div>Portfolio project &middot; PHP <?= e(PHP_VERSION) ?></div>
</footer>

<!-- Shared confirmation dialog, driven by data attributes -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmModalLabel">Please confirm</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0" id="confirmModalMessage">Are you sure?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmModalAccept">Yes, continue</button>
            </div>
        </div>
    </div>
</div>

<script src="<?= e(url('assets/vendor/js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>