(() => {
	const state = {
		isProcessing: false,
		progress: 0,
		processed: 0,
		total: 0,
		autoRestart: true, // Flag to control auto-restarting of batches
		isCancelling: false, // New flag to track if cancellation is in progress
	};

	const elements = {
		startButton: document.getElementById("bulk-offload-button"),
		cancelButton: document.getElementById("bulk-offload-cancel-button"),
		progressContainer: document.getElementById("progress-container"),
		progressBar: document.getElementById("offload-progress"),
		progressBarContainer: document.querySelector(".progress-bar-container"),
		progressTitle: document.getElementById("progress-title"),
		progressText: document.getElementById("progress-text"),
		processedCount: document.getElementById("processed-count"),
		totalCount: document.getElementById("total-count"),
		messageContainer: document.createElement("div"),
	};

	const init = () => {
		elements.messageContainer.id = "advmo-message-container";
		elements.progressContainer.parentNode.insertBefore(
			elements.messageContainer,
			elements.progressContainer,
		);

		if (elements.startButton) {
			elements.startButton.addEventListener("click", startBulkOffload);
		}

		if (elements.cancelButton) {
			elements.cancelButton.addEventListener("click", cancelBulkOffload);
		}

		if (elements.progressContainer.dataset.status === "processing") {
			if (elements.startButton) {
				elements.startButton.disabled = true;
			}
			elements.progressContainer.style.display = "block";
			checkProgress();
		} else if (elements.progressContainer.dataset.status === "cancelled") {
			// If the status is cancelled on page load, reset the interface
			resetInterface();
		}
	};

	const resetInterface = () => {
		// Reset the interface to allow starting a new offload process
		if (elements.startButton) {
			elements.startButton.disabled = false;
		}
		if (elements.cancelButton) {
			elements.cancelButton.disabled = false;
			elements.cancelButton.style.display = "none";
		}
		elements.progressContainer.style.display = "none";
		state.isProcessing = false;
		state.isCancelling = false;
		state.autoRestart = true;
	};

	const showMessage = (message, isError = false) => {
		elements.messageContainer.textContent = message;
		elements.messageContainer.className = isError
			? "error-message"
			: "success-message";
		elements.messageContainer.style.display = "block";
		setTimeout(() => {
			elements.messageContainer.style.display = "none";
		}, 5000);
	};

	const startBulkOffload = async (e) => {
		if (e) e.preventDefault();
		elements.startButton.disabled = true;
		elements.progressContainer.style.display = "block";
		elements.progressBarContainer.style.display = "block";
		elements.progressTitle.style.display = "block";
		if (elements.cancelButton) {
			elements.cancelButton.style.display = "inline-block"; // Make sure cancel button is visible
			elements.cancelButton.disabled = false;
		}
		state.isCancelling = false;

		const formData = new FormData();
		formData.append("action", "advmo_start_bulk_offload");
		formData.append(
			"bulk_offload_nonce",
			advmo_ajax_object.bulk_offload_nonce,
		);
		formData.append("batch_size", 200);

		try {
			const response = await fetch(advmo_ajax_object.ajax_url, {
				method: "POST",
				credentials: "same-origin",
				body: formData,
			});
			const data = await response.json();

			if (data.success) {
				state.isProcessing = true;
				checkProgress();
			} else {
				showMessage(
					`Failed to start bulk offload process: ${data.data?.message || "Unknown error"}`,
					true,
				);
				elements.startButton.disabled = false;
			}
		} catch (error) {
			console.error("Error:", error);
			showMessage(
				"An error occurred while starting the bulk offload process",
				true,
			);
			elements.startButton.disabled = false;
		}
	};

	const checkProgress = async () => {
		// Don't check progress if we're cancelling
		if (state.isCancelling) {
			return;
		}

		const formData = new FormData();
		formData.append("action", "advmo_check_bulk_offload_progress");
		formData.append(
			"bulk_offload_nonce",
			advmo_ajax_object.bulk_offload_nonce,
		);

		try {
			const response = await fetch(advmo_ajax_object.ajax_url, {
				method: "POST",
				credentials: "same-origin",
				body: formData,
			});
			const data = await response.json();

			if (data.success) {
				// Check if the process was cancelled
				if (data.data.status === "cancelled") {
					processCancelled();
					return;
				}
				updateProgressUI(data.data);
			} else {
				console.log(data.data);
				showMessage(`Failed to check progress.`, true);
				// If we can't check progress, assume we need to reset the interface
				resetInterface();
			}
		} catch (error) {
			console.error("Error:", error);
			showMessage("An error occurred while checking the progress", true);
			// If we can't check progress, assume we need to reset the interface
			resetInterface();
		}
	};

	const updateProgressUI = (progressData) => {
		// If we're cancelling, ignore updates
		if (state.isCancelling) {
			return;
		}

		state.processed = parseInt(progressData.processed) || 0;
		state.total = parseInt(progressData.total) || 0;
		state.progress =
			state.processed !== 0 && state.total !== 0
				? (state.processed / state.total) * 100
				: 0;
		state.errors = parseInt(progressData.errors) || 0;

		requestAnimationFrame(() => {
			elements.progressBar.style.width = `${state.progress}%`;
			elements.progressBar.setAttribute("aria-valuenow", state.progress);
			elements.progressText.textContent = `${Math.round(
				state.progress,
			)}%`;
			elements.processedCount.textContent = state.processed;
			elements.totalCount.textContent = state.total;

			if (state.total === state.processed && state.total !== 0) {
				// Current batch is complete - directly query for more items
				if (state.autoRestart) {
					// Check if there are more items to process
					elements.progressText.textContent = "Checking for more items...";
					// Use the existing utility function to get the count
					getUnoffloadedMediaCount().then(count => {
						if (count > 0) {
							// There are more items to process
							elements.progressText.textContent = `Batch complete! Starting next batch of ${count} files...`;
							setTimeout(() => {
								startBulkOffload();
							}, 3000);
						} else {
							// No more items to process
							completeOffload(state.errors);
						}
					}).catch(() => {
						// In case of error, finish the process
						completeOffload(state.errors);
					});
				} else {
					completeOffload(state.errors);
				}
			} else if (state.total === 0) {
				noFilesToOffload();
			} else {
				setTimeout(checkProgress, 5000);
			}
		});
	};

	// Function to get the count of unoffloaded media items
	const getUnoffloadedMediaCount = async () => {
		// If we're cancelling, don't check for more items
		if (state.isCancelling) {
			return 0;
		}

		const formData = new FormData();
		formData.append("action", "advmo_check_unoffloaded_count");
		formData.append(
			"bulk_offload_nonce",
			advmo_ajax_object.bulk_offload_nonce,
		);

		try {
			const response = await fetch(advmo_ajax_object.ajax_url, {
				method: "POST",
				credentials: "same-origin",
				body: formData,
			});
			const data = await response.json();

			if (data.success) {
				return data.data.count;
			} else {
				throw new Error(data.data?.message || "Failed to get count");
			}
		} catch (error) {
			console.error("Error getting unoffloaded count:", error);
			showMessage("Error checking for more files to process", true);
			return 0; // Return 0 to indicate an error or no more files
		}
	};

	const completeOffload = (errors) => {
		elements.progressText.textContent = "Offload complete!";

		if (errors > 0) {
			elements.progressText.textContent = `Offload complete! ${errors} files failed to offload.`;
		}
		if (elements.startButton) {
			elements.startButton.disabled = false;
		}
		if (elements.cancelButton) {
			elements.cancelButton.disabled = true;
			elements.cancelButton.style.display = "none";
		}
		elements.progressBarContainer.style.display = "none";
		elements.progressTitle.style.display = "none";
		state.isProcessing = false;
	};

	const processCancelled = () => {
		elements.progressText.textContent = "Offload process cancelled.";
		if (elements.startButton) {
			elements.startButton.disabled = false;
		}
		if (elements.cancelButton) {
			elements.cancelButton.disabled = true;
			elements.cancelButton.style.display = "none";
		}
		elements.progressBarContainer.style.display = "none";
		elements.progressTitle.style.display = "none";
		state.isProcessing = false;
		state.isCancelling = false;
		showMessage("Bulk offload process cancelled successfully.");
	};

	const noFilesToOffload = () => {
		elements.progressText.textContent = "No files to offload";
		if (elements.startButton) {
			elements.startButton.disabled = false;
		}
		elements.progressContainer.style.display = "none";
		showMessage("No files to offload");
		state.isProcessing = false;
	};

	const cancelBulkOffload = async (e) => {
		e.preventDefault();
		
		// Already cancelling, don't send another request
		if (state.isCancelling) {
			return;
		}
		
		state.isCancelling = true;
		state.autoRestart = false; // Disable auto-restart when cancelling
		
		if (elements.cancelButton) {
			elements.cancelButton.disabled = true;
		}
		
		elements.progressText.textContent = "Cancelling...";

		const formData = new FormData();
		formData.append("action", "advmo_cancel_bulk_offload");
		formData.append(
			"bulk_offload_nonce",
			advmo_ajax_object.bulk_offload_nonce,
		);

		try {
			const response = await fetch(advmo_ajax_object.ajax_url, {
				method: "POST",
				credentials: "same-origin",
				body: formData,
			});
			const data = await response.json();

			if (data.success) {
				processCancelled();
			} else {
				console.log(data.data?.message || "Unknown error");
				showMessage(
					`Failed to cancel bulk offload process: ${data.data?.message || "Unknown error"}`,
					true,
				);
				// Even if cancellation fails in the backend, reset the UI
				processCancelled();
			}
		} catch (error) {
			console.error("Error:", error);
			showMessage(
				"An error occurred while cancelling the bulk offload process",
				true,
			);
			// Even if cancellation fails due to an error, reset the UI
			processCancelled();
		}
	};

	document.addEventListener("DOMContentLoaded", init);
})();