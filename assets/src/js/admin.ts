// eslint-disable-next-line camelcase
declare const mcpress_vars: {
	chat_url: string;
	chat_init_url: string;
	execute_tool_url: string;
	nonce: string;
};

// Array to store conversation messages
const messages: Array< {
	role: string;
	content?: string;
	tool_calls?: Array< {
		id: string;
		type: string;
		function: { name: string; arguments: string };
	} >;
	tool_call_id?: string;
} > = [];

const chatLog = document.getElementById( 'mcpress-chat-log' ) as HTMLElement;
const userInput = document.getElementById(
	'mcpress-user-input'
) as HTMLTextAreaElement;
const sendButton = document.getElementById(
	'mcpress-send-button'
) as HTMLButtonElement;
const spinner = document.querySelector( '.spinner' ) as HTMLElement;

let currentToolCalls: Array< any > | null = null;
let currentMessagesHistory: Array< any > | null = null;

// Append messages to chat
function appendMessage(
	sender: string,
	message: string,
	type: 'user' | 'llm' | 'error' | 'tool-execution' | 'system'
): void {
	if ( ! message ) {
		return;
	}
	let messageClass = 'user-message';
	if ( type === 'llm' ) {
		messageClass = 'llm-message';
	} else if ( type === 'error' ) {
		messageClass = 'error-message';
	} else if ( type === 'tool-execution' ) {
		messageClass = 'tool-execution-message';
	}

	let formattedMessage = message; // Initialize with the original message

	// Markdown formatting only applies if the message is not a tool-execution
	if ( type !== 'tool-execution' ) {
		formattedMessage = message
			.replace( /\*\*(.*?)\*\*/g, '<strong>$1</strong>' ) // bold
			.replace( /`(.*?)`/g, '<code>$1</code>' ) // inline code
			.replace( /\n/g, '<br>' ); // newlines
	}

	const messageDiv = document.createElement( 'div' );
	messageDiv.className = `mcpress-chat-message ${ messageClass }`;
	messageDiv.innerHTML = `<strong>${ sender }:</strong> ${ formattedMessage }`;
	chatLog.appendChild( messageDiv );

	// Scroll to bottom
	chatLog.scrollTop = chatLog.scrollHeight;
}

// Helper to create/update a live LLM bubble
function createLiveLLMBubble(): HTMLElement {
	const messageDiv = document.createElement( 'div' );
	messageDiv.className = 'mcpress-chat-message llm-message';
	messageDiv.innerHTML = `<strong>LLM:</strong> <span class="mcpress-live-content"></span>`;
	chatLog.appendChild( messageDiv );
	chatLog.scrollTop = chatLog.scrollHeight;
	return messageDiv.querySelector( '.mcpress-live-content' ) as HTMLElement;
}

// Send button click handler
sendButton.addEventListener( 'click', () => sendMessage() );

// Function to send message to the API
async function sendMessage(): Promise< void > {
	const messageText = userInput.value.trim();
	if ( messageText === '' && messages.length > 0 && ! currentToolCalls ) {
		// Only proceed if there's a message from user or if it's a follow-up (tool execution)
		// but not if it's an empty message and no tool confirmation is pending.
		return;
	}

	if ( messageText !== '' ) {
		appendMessage( 'You', messageText, 'user' );
		messages.push( { role: 'user', content: messageText } );
		userInput.value = '';
	}

	spinner.classList.add( 'is-active' );
	sendButton.disabled = true;

	try {
		// eslint-disable-next-line camelcase
		const response = await fetch( mcpress_vars.chat_url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				// eslint-disable-next-line camelcase
				'X-WP-Nonce': mcpress_vars.nonce,
				Accept: 'text/event-stream',
				'X-MCPress-Stream': '1',
			},
			credentials: 'include',
			body: JSON.stringify( {
				messages, // Send the entire message history
			} ),
		} );

		const contentType = response.headers.get( 'Content-Type' ) || '';

		if ( response.body && contentType.includes( 'text/event-stream' ) ) {
			// Streaming path (SSE)
			const liveSpan = createLiveLLMBubble();
			let accumulated = '';
			let streamedToolCalls: Array< any > | null = null;
			const toolCallAccumulator: Record<
				number,
				{
					id: string;
					type: string;
					function: { name: string; arguments: string };
				}
			> = {};

			const reader = response.body.getReader();
			const decoder = new TextDecoder();
			let buffer = '';

			while ( true ) {
				const { value, done } = await reader.read();
				if ( done ) {
					break;
				}
				buffer += decoder.decode( value, { stream: true } );

				let frameEnd: number;
				while ( ( frameEnd = buffer.indexOf( '\n\n' ) ) !== -1 ) {
					const frame = buffer.slice( 0, frameEnd );
					buffer = buffer.slice( frameEnd + 2 );

					// Parse SSE frame for data:
					const lines = frame.split( '\n' );
					const dataLine = lines.find( ( l ) =>
						l.startsWith( 'data:' )
					);
					if ( ! dataLine ) {
						continue;
					}
					const json = dataLine.slice( 5 ).trim();
					if ( ! json ) {
						continue;
					}

					let evt: any;
					try {
						evt = JSON.parse( json );
					} catch {
						continue;
					}

					if (
						evt.type === 'delta' &&
						typeof evt.content === 'string'
					) {
						accumulated += evt.content;
						const formatted = accumulated
							.replace( /\*\*(.*?)\*\*/g, '<strong>$1</strong>' )
							.replace( /`(.*?)`/g, '<code>$1</code>' )
							.replace( /\n/g, '<br>' );
						liveSpan.innerHTML = formatted;
						chatLog.scrollTop = chatLog.scrollHeight;
					} else if (
						evt.type === 'tool_calls' &&
						Array.isArray( evt.tool_calls )
					) {
						streamedToolCalls = evt.tool_calls;
					} else if (
						evt.type === 'tool_call_delta' &&
						Array.isArray( evt.tool_calls )
					) {
						for ( const delta of evt.tool_calls ) {
							const idx =
								typeof delta.index === 'number'
									? delta.index
									: null;
							if ( idx === null ) {
								continue;
							}
							if ( ! toolCallAccumulator[ idx ] ) {
								toolCallAccumulator[ idx ] = {
									id: delta.id || '',
									type: delta.type || 'function',
									function: {
										name:
											( delta.function &&
												delta.function.name ) ||
											'',
										arguments: '',
									},
								};
							}
							if ( delta.id ) {
								toolCallAccumulator[ idx ].id = delta.id;
							}
							if ( delta.function?.name ) {
								toolCallAccumulator[ idx ].function.name =
									delta.function.name;
							}
							if ( delta.function?.arguments ) {
								toolCallAccumulator[ idx ].function.arguments +=
									delta.function.arguments;
							}
						}
						streamedToolCalls =
							Object.values( toolCallAccumulator );
					} else if ( evt.type === 'error' ) {
						appendMessage(
							'Error',
							evt.message || 'Streaming error',
							'error'
						);
					} else if ( evt.type === 'done' ) {
						if (
							! streamedToolCalls ||
							streamedToolCalls.length === 0
						) {
							const accumulatedCalls =
								Object.values( toolCallAccumulator );
							if ( accumulatedCalls.length > 0 ) {
								streamedToolCalls = accumulatedCalls;
							}
						}
						if (
							streamedToolCalls &&
							streamedToolCalls.length > 0
						) {
							// Present tool confirmation UI similar to non-streaming flow.
							currentToolCalls = streamedToolCalls;
							currentMessagesHistory = [
								...messages,
								{
									role: 'assistant',
									tool_calls: streamedToolCalls,
								},
							];

							const notice =
								'I am suggesting to use a tool to help you with your request.';
							appendMessage( 'LLM', notice, 'llm' );
							messages.push( {
								role: 'assistant',
								content: notice,
							} );

							const yesButton =
								document.createElement( 'button' );
							yesButton.id = 'mcpress-tool-yes';
							yesButton.className = 'button button-primary';
							yesButton.textContent = 'Yes';
							yesButton.addEventListener( 'click', () =>
								handleToolDecision( 'yes' )
							);

							const noButton = document.createElement( 'button' );
							noButton.id = 'mcpress-tool-no';
							noButton.className = 'button';
							noButton.textContent = 'No';
							noButton.addEventListener( 'click', () =>
								handleToolDecision( 'no' )
							);

							const buttonContainer =
								document.createElement( 'div' );
							buttonContainer.className = 'mcpress-tool-decision';
							buttonContainer.appendChild( yesButton );
							buttonContainer.appendChild( noButton );
							chatLog.appendChild( buttonContainer );
							chatLog.scrollTop = chatLog.scrollHeight;
						} else {
							// Finalize streamed assistant content
							if ( accumulated ) {
								messages.push( {
									role: 'assistant',
									content: accumulated,
								} );
							} else {
								appendMessage(
									'LLM',
									'LLM did not provide a follow-up response.',
									'llm'
								);
								messages.push( {
									role: 'assistant',
									content:
										'LLM did not provide a follow-up response.',
								} );
							}
							// Reset tool confirmation state
							currentToolCalls = null;
							currentMessagesHistory = null;
						}
					}
				}
			}
		} else {
			// Fallback to non-streaming JSON behavior
			const data = await response.json();

			if ( data.success ) {
				if ( data.requires_confirmation && data.tool_calls ) {
					// Store tool calls and messages for later execution
					currentToolCalls = data.tool_calls;
					currentMessagesHistory = data.messages; // This should include the assistant's tool_calls message

					appendMessage( 'LLM', data.message, 'llm' ); // "I am suggesting to use a tool..."
					messages.push( {
						role: 'assistant',
						content: data.message,
					} );

					// Display Yes/No buttons
					const yesButton = document.createElement( 'button' );
					yesButton.id = 'mcpress-tool-yes';
					yesButton.className = 'button button-primary';
					yesButton.textContent = 'Yes';
					yesButton.addEventListener( 'click', () =>
						handleToolDecision( 'yes' )
					);

					const noButton = document.createElement( 'button' );
					noButton.id = 'mcpress-tool-no';
					noButton.className = 'button';
					noButton.textContent = 'No';
					noButton.addEventListener( 'click', () =>
						handleToolDecision( 'no' )
					);

					const buttonContainer = document.createElement( 'div' );
					buttonContainer.className = 'mcpress-tool-decision';
					buttonContainer.appendChild( yesButton );
					buttonContainer.appendChild( noButton );
					chatLog.appendChild( buttonContainer );
					chatLog.scrollTop = chatLog.scrollHeight;
				} else {
					// Normal LLM response or final response after tool execution
					const llmResponseContent: string = data.message;
					if ( llmResponseContent ) {
						appendMessage( 'LLM', llmResponseContent, 'llm' );
						if ( ! currentToolCalls ) {
							messages.push( {
								role: 'assistant',
								content: llmResponseContent,
							} );
						}
					} else {
						// Fallback for empty LLM response
						appendMessage(
							'LLM',
							'LLM did not provide a follow-up response.',
							'llm'
						);
						if ( ! currentToolCalls ) {
							messages.push( {
								role: 'assistant',
								content:
									'LLM did not provide a follow-up response.',
							} );
						}
					}
					// Reset tool confirmation state
					currentToolCalls = null;
					currentMessagesHistory = null;
				}
			} else {
				appendMessage(
					'Error',
					data.message || 'An unknown error occurred.',
					'error'
				);
				// Reset tool confirmation state on error
				currentToolCalls = null;
				currentMessagesHistory = null;
			}
		}
	} catch ( error ) {
		appendMessage( 'Error', `API request failed: ${ error }`, 'error' );
		// Reset tool confirmation state on error
		currentToolCalls = null;
		currentMessagesHistory = null;
	} finally {
		// Only remove spinner and re-enable button if no confirmation is pending
		if ( ! currentToolCalls ) {
			spinner.classList.remove( 'is-active' );
			sendButton.disabled = false;
		}
	}
}

// Function to handle tool decision (Yes/No buttons)
async function handleToolDecision( decision: 'yes' | 'no' ) {
	spinner.classList.add( 'is-active' );
	sendButton.disabled = true;

	// Remove buttons to prevent multiple clicks
	document.getElementById( 'mcpress-tool-yes' )?.remove();
	document.getElementById( 'mcpress-tool-no' )?.remove();

	if ( decision === 'yes' && currentToolCalls && currentMessagesHistory ) {
		appendMessage( 'You', 'Yes, execute the tool.', 'user' );
		// Push the user's "Yes" response into the message history
		messages.push( { role: 'user', content: 'Yes, execute the tool.' } );

		try {
			// eslint-disable-next-line camelcase
			const response = await fetch( mcpress_vars.execute_tool_url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					// eslint-disable-next-line camelcase
					'X-WP-Nonce': mcpress_vars.nonce,
				},
				credentials: 'include',
				body: JSON.stringify( {
					tool_calls: currentToolCalls,
					messages: currentMessagesHistory,
				} ),
			} ).then( ( res ) => res.json() );

			if ( response.success ) {
				appendMessage( 'LLM', response.message, 'llm' );
				// Push the final LLM response into the main messages array
				messages.push( {
					role: 'assistant',
					content: response.message,
				} );
			} else {
				appendMessage(
					'Error',
					response.message ||
						'An unknown error occurred during tool execution.',
					'error'
				);
				// Even on error, push an assistant message to keep history consistent
				messages.push( {
					role: 'assistant',
					content:
						response.message ||
						'An unknown error occurred during tool execution.',
				} );
			}
		} catch ( error ) {
			appendMessage(
				'Error',
				`Tool execution API request failed: ${ error }`,
				'error'
			);
			messages.push( {
				role: 'assistant',
				content: `Tool execution API request failed: ${ error }`,
			} );
		}
	} else if ( decision === 'no' ) {
		appendMessage( 'You', 'No, do not execute the tool.', 'user' );
		messages.push( {
			role: 'user',
			content: 'No, do not execute the tool.',
		} ); // Push user's "No"
		appendMessage(
			'MCP Agent',
			'Tool execution declined. How else can I assist?',
			'llm'
		);
		messages.push( {
			role: 'assistant',
			content: 'Tool execution declined. How else can I assist?',
		} ); // Push assistant's response
	}

	// Clear stored tool data
	currentToolCalls = null;
	currentMessagesHistory = null;

	spinner.classList.remove( 'is-active' );
	sendButton.disabled = false;
}

// Handle Enter key for sending message (single handler; avoids double-send with streaming Accept header)
userInput.addEventListener( 'keypress', ( e: KeyboardEvent ) => {
	if ( e.key === 'Enter' && ! e.shiftKey ) {
		e.preventDefault();
		sendMessage(); // Call sendMessage directly
	}
} );

// Fetch initial chat context on page load
document.addEventListener( 'DOMContentLoaded', async () => {
	spinner.classList.add( 'is-active' );
	sendButton.disabled = true;

	try {
		// eslint-disable-next-line camelcase
		const response = await fetch( mcpress_vars.chat_init_url, {
			method: 'GET',
			headers: {
				'Content-Type': 'application/json',
				// eslint-disable-next-line camelcase
				'X-WP-Nonce': mcpress_vars.nonce,
			},
			credentials: 'include',
		} );

		const data = await response.json();

		if ( data.success && Array.isArray( data.messages ) ) {
			// Add system message to the history
			messages.push( ...data.messages );

			// Display the welcome message as a system message
			if ( data.display_initial_message ) {
				appendMessage(
					'System',
					data.display_initial_message,
					'system'
				);
			}
		} else {
			appendMessage(
				'Error',
				data.message || 'Failed to load initial chat context.',
				'error'
			);
		}
	} catch ( error ) {
		appendMessage(
			'Error',
			`Failed to fetch initial chat context: ${ error }`,
			'error'
		);
	} finally {
		spinner.classList.remove( 'is-active' );
		sendButton.disabled = false;
	}
} );
