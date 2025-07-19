// eslint-disable-next-line camelcase
declare const mcpress_vars: {
	chat_url: string;
	execute_tool_url: string;
	nonce: string;
};

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
	type: 'user' | 'llm' | 'error' | 'tool-suggestion'
): void {
	if ( ! message ) {
		return;
	}
	let messageClass = 'user-message';
	if ( type === 'llm' ) {
		messageClass = 'llm-message';
	} else if ( type === 'error' ) {
		messageClass = 'error-message';
	} else if ( type === 'tool-suggestion' ) {
		messageClass = 'tool-suggestion-message';
	}

	// For tool suggestions, message might contain HTML buttons
	const isHtmlContent = type === 'tool-suggestion';

	const messageDiv = document.createElement( 'div' );
	messageDiv.className = `mcpress-chat-message ${ messageClass }`;

	if ( isHtmlContent ) {
		messageDiv.innerHTML = `<strong>${ sender }:</strong> ${ message }`;
	} else {
		// Apply Markdown formatting for regular messages
		const formattedMessage = message
			.replace( /\*\*(.*?)\*\*/g, '<strong>$1</strong>' ) // bold
			.replace( /`(.*?)`/g, '<code>$1</code>' ) // inline code
			.replace( /\n/g, '<br>' ); // newlines
		messageDiv.innerHTML = `<strong>${ sender }:</strong> ${ formattedMessage }`;
	}

	chatLog.appendChild( messageDiv );

	// Scroll to bottom
	chatLog.scrollTop = chatLog.scrollHeight;
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
			} else {
				appendMessage(
					'Error',
					response.message ||
						'An unknown error occurred during tool execution.',
					'error'
				);
			}
		} catch ( error ) {
			appendMessage(
				'Error',
				`Tool execution API request failed: ${ error }`,
				'error'
			);
		}
	} else if ( decision === 'no' ) {
		appendMessage( 'You', 'No, do not execute the tool.', 'user' );
		appendMessage( 'MCP Agent', 'Tool execution declined.', 'llm' );
	}

	// Clear stored tool data
	currentToolCalls = null;
	currentMessagesHistory = null;

	spinner.classList.remove( 'is-active' );
	sendButton.disabled = false;
}

// Send button click handler
sendButton.addEventListener( 'click', async () => {
	const message = userInput.value.trim();
	if ( message === '' ) {
		return;
	}

	appendMessage( 'You', message, 'user' );
	userInput.value = '';
	spinner.classList.add( 'is-active' );
	sendButton.disabled = true;

	try {
		// Send initial CHAT API request
		// eslint-disable-next-line camelcase
		const response = await fetch( mcpress_vars.chat_url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				// eslint-disable-next-line camelcase
				'X-WP-Nonce': mcpress_vars.nonce,
			},
			credentials: 'include',
			body: JSON.stringify( {
				message,
			} ),
		} ).then( ( res ) => res.json() );

		if ( response.success ) {
			if ( response.tool_suggested ) {
				currentToolCalls = response.tool_calls;
				currentMessagesHistory = response.messages;

				const toolName =
					currentToolCalls &&
					currentToolCalls[ 0 ] &&
					currentToolCalls[ 0 ].function &&
					currentToolCalls[ 0 ].function.name
						? `"${ currentToolCalls[ 0 ].function.name }"`
						: 'a tool';
				// Append a tool suggestion message with buttons
				appendMessage(
					'MCP Agent',
					`The AI suggested executing ${ toolName }. Do you want to execute it?<br>` +
						'<button id="mcpress-tool-yes" class="button button-secondary">Yes</button> ' +
						'<button id="mcpress-tool-no" class="button button-secondary">No</button>',
					'tool-suggestion'
				);

				// Add event listeners for the new buttons
				document
					.getElementById( 'mcpress-tool-yes' )
					?.addEventListener( 'click', () =>
						handleToolDecision( 'yes' )
					);
				document
					.getElementById( 'mcpress-tool-no' )
					?.addEventListener( 'click', () =>
						handleToolDecision( 'no' )
					);
			} else {
				// Regular LLM response
				appendMessage( 'LLM', response.message, 'llm' );
			}
		} else {
			appendMessage(
				'Error',
				response.message || 'An unknown error occurred.',
				'error'
			);
		}
	} catch ( error ) {
		appendMessage( 'Error', `API request failed: ${ error }`, 'error' );
	} finally {
		spinner.classList.remove( 'is-active' );
		sendButton.disabled = false;
	}
} );

// Handle Enter key for sending message
userInput.addEventListener( 'keypress', ( e: KeyboardEvent ) => {
	if ( e.key === 'Enter' && ! e.shiftKey ) {
		e.preventDefault();
		sendButton.click();
	}
} );
