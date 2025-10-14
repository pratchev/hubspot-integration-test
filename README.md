# HubSpot Integration Test

A single-file PHP application for testing HubSpot API integration with forms management and data visualization.

## Features

- **Forms Management**: Dropdown selection of forms from HubSpot
- **Form Properties**: Scrollable one-column grid showing first 10 form properties
- **Form Data Grid**: Paginated view (25 records per page) with navigation controls
- **Sorting**: Data sorted by creation date (descending)
- **Responsive Design**: CarePatrol-inspired color scheme and styling

## Requirements

- PHP 7.4 or higher
- HubSpot Private App Access Token
- cURL extension enabled

## Setup

1. Clone this repository
2. Copy `.env.example` to `.env` and update with your HubSpot Private App token:
   ```bash
   cp .env.example .env
   ```
   Then edit `.env` and replace `your_hubspot_private_app_token_here` with your actual token.

   **Alternative**: You can also directly edit `hubspot-integration-test.php` and replace `YOUR_HUBSPOT_TOKEN_HERE` with your token.

3. Run the application using PHP's built-in server:
   ```bash
   php -S localhost:8000
   ```

4. Open your browser and navigate to: `http://localhost:8000/hubspot-integration-test.php`

## API Endpoints Used

- **Forms**: `/marketing/v3/forms` (with fallback to `/forms/v2/forms`)
- **Form Details**: `/marketing/v3/forms/{formId}` (with fallback to `/forms/v2/forms/{formId}`)
- **Form Submissions**: `/crm/v3/extensions/forms/{formId}/submissions`

## Configuration

The application uses HubSpot's API v3 endpoints with automatic fallback to v2 when needed. Make sure your Private App token has the following scopes:

- `forms`
- `crm.lists.read`
- `crm.objects.contacts.read`

## Interface

- **Forms Dropdown**: Select from available HubSpot forms
- **Form ID Display**: Shows the selected form's unique identifier
- **Properties Grid**: Displays form field details (name, type, label)
- **Data Table**: Shows form submissions with pagination controls
- **Navigation**: First, Previous, Next, Last page controls with record counts

## Browser Compatibility

Tested on modern browsers including Chrome, Firefox, Safari, and Edge.