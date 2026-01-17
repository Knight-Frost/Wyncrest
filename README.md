# Nexus

## Overview

Nexus is a full-stack property rental platform designed to manage the complete lifecycle of rental properties within a single, cohesive system. The platform is intended to support landlords, tenants, and administrators by centralizing property listings, tenant relationships, contracts, payments, and operational oversight.

The backend forms the core of the system and is largely complete. It is responsible for enforcing business rules, managing persistent data, handling application workflows, and maintaining system integrity. While the backend already supports the primary functionality of the platform, it is expected to receive targeted improvements and refinements as the course progresses, such as optimization, additional validation, and incremental feature enhancements.

The frontend represents the user-facing layer of the platform. At the current stage, frontend development is limited to establishing a reliable development environment that will support future interface development and frontend–backend integration.

Development follows an incremental approach. Early deliverables focus on environment setup and architectural foundations, while later deliverables build on this base to introduce user interfaces, integration, and system refinements.

---

## How the Project Is Organized

The project is divided into two primary components:

- **Backend** – Responsible for data management, business rules, and application logic  
- **Frontend** – Responsible for user interaction and client-side behavior  

Each component is described below to illustrate how the overall system operates.

---

## Backend

### Backend Overview

The backend manages the core functionality of the Nexus platform. It handles request routing, business logic execution, data persistence, and structured responses to client requests.

Although backend development is largely complete, it is **not graded as part of Deliverable 1**. The backend is included in this repository because the same repository will be used for all subsequent course deliverables.

---

### Backend Folder Structure

```

app/
bootstrap/
config/
database/
routes/
storage/
tests/
vendor/
artisan
composer.json

```

---

### Backend Folder Responsibilities

| Folder / File | Purpose |
|--------------|--------|
| `app/` | Core application logic, including controllers, models, and services |
| `routes/` | Route definitions that map incoming requests to backend logic |
| `database/` | Database migrations, schema definitions, and seed data |
| `config/` | Centralized configuration for backend services |
| `storage/` | Logs, cached files, and generated application data |
| `tests/` | Automated tests for backend functionality |
| `bootstrap/` | Application bootstrapping and startup configuration |
| `vendor/` | Third-party dependencies managed by Composer |
| `artisan` | Command-line utility for backend development and maintenance tasks |

---

### Backend Operation (High Level)

1. Requests are received through defined routes  
2. Routes delegate requests to controllers or service layers  
3. Business logic processes the request  
4. Data is read from or written to the database  
5. A structured response is returned to the client  

Backend functionality will be formally introduced and evaluated in later deliverables.

---

## Frontend (Deliverable 1)

### Frontend Overview

The frontend component of Nexus has not yet been fully implemented. At this stage, the focus is on establishing a stable and maintainable frontend development environment that will support future user interface development.

Deliverable 1 is dedicated to this setup process. It includes initializing a React project, configuring the build system, integrating a styling framework, and verifying that the development environment runs correctly in a local context.

No application features or user workflows have been implemented yet. The current frontend state exists solely as a foundation for future development.

---

### Technologies Used

| Technology | Purpose |
|-----------|--------|
| Node.js | JavaScript runtime |
| npm | Dependency management |
| Vite | Frontend build and development tool |
| React | Frontend framework |
| Tailwind CSS | Styling framework |

---

### Frontend Folder Structure

```

frontend/
├── public/
├── src/
│   ├── App.jsx
│   ├── main.jsx
│   └── index.css
├── index.html
├── package.json
├── package-lock.json
├── vite.config.js
└── .gitignore

````

---

### Frontend File Responsibilities

| File | Purpose |
|-----|--------|
| `index.html` | Base HTML document used to load the frontend application |
| `main.jsx` | Entry point that initializes the React application |
| `App.jsx` | Temporary root component used to verify environment setup |
| `index.css` | Global styles and Tailwind CSS import |
| `vite.config.js` | Vite configuration and Tailwind integration |
| `package.json` | Frontend dependencies and development scripts |

---

### Frontend Operation (Current State)

1. Vite starts the local development server  
2. `index.html` loads the React application shell  
3. `main.jsx` mounts a minimal React component  
4. Tailwind CSS is applied to confirm styling configuration  

---

### Running the Frontend

```bash
cd frontend
npm install
npm run dev
````

The development server runs locally at:

```
http://localhost:5173
```

---

### Deliverable 1 Requirements Status

| Requirement                                | Status   |
| ------------------------------------------ | -------- |
| Node.js installed                          | Complete |
| React project initialized with Vite        | Complete |
| Tailwind CSS installed and configured      | Complete |
| Development server runs successfully       | Complete |
| Frontend environment ready for development | Complete |

