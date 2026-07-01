<footer class="kasi-footer container text-center">
	<div class="row justify-content-center">
		<div class="col-12 col-md-8">
			<div class="d-flex flex-column flex-md-row justify-content-center align-items-center gap-3 gap-md-4 border-top pt-4 pb-2" style="border-color: rgba(74, 124, 89, 0.15) !important;">
				<a href="<?= htmlspecialchars(kasi_exchange_url('login.php') . '?switch=1', ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none text-uppercase">Login / Switch Account</a>
				<a href="<?= htmlspecialchars(kasi_exchange_url('about.php'), ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none text-uppercase">About Us</a>
				<a href="<?= htmlspecialchars(kasi_exchange_url('connect.php'), ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none text-uppercase">Connect / Support</a>
			</div>
		</div>
	</div>
	<div class="row mt-2">
		<div class="col-12">
			<p class="text-muted small mb-0" style="opacity: 0.6; font-size: 0.75rem;">&copy; <?php echo date('Y'); ?> Kasi Exchange. Built Premium.</p>
		</div>
	</div>
</footer>
