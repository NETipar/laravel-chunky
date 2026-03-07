# React Usage

The `@netipar/chunky-react` package provides a `useChunkUpload` hook for React 18+ and React 19+.

## Installation

```bash
npm install @netipar/chunky-react
```

## Basic Upload

```tsx
import { useChunkUpload } from '@netipar/chunky-react';

function FileUpload() {
    const { upload, progress, isUploading, isComplete, error } = useChunkUpload();

    const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) upload(file);
    };

    return (
        <div>
            <input type="file" onChange={handleChange} disabled={isUploading} />

            {isUploading && (
                <div>
                    <progress value={progress} max={100} />
                    <span>{Math.round(progress)}%</span>
                </div>
            )}

            {isComplete && <p style={{ color: 'green' }}>Upload complete!</p>}
            {error && <p style={{ color: 'red' }}>{error}</p>}
        </div>
    );
}
```

## With All Controls

```tsx
import { useChunkUpload } from '@netipar/chunky-react';

function FullUpload() {
    const {
        upload, pause, resume, cancel, retry,
        progress, isUploading, isPaused, isComplete, error,
        uploadedChunks, totalChunks, currentFile,
        onComplete, onError,
    } = useChunkUpload({
        maxConcurrent: 3,
        autoRetry: true,
        maxRetries: 3,
    });

    // Subscribe to events
    React.useEffect(() => {
        const unsub = onComplete((result) => {
            console.log('Upload done:', result.uploadId);
            // Navigate, update state, etc.
        });

        return unsub;
    }, [onComplete]);

    const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) upload(file);
    };

    return (
        <div>
            {!isUploading && !isComplete && (
                <label>
                    <input type="file" onChange={handleChange} style={{ display: 'none' }} />
                    <div className="dropzone">Click to select a file</div>
                </label>
            )}

            {(isUploading || isPaused) && (
                <div>
                    <p>{currentFile?.name}</p>
                    <progress value={progress} max={100} />
                    <p>{uploadedChunks} / {totalChunks} chunks - {Math.round(progress)}%</p>
                    <button onClick={isPaused ? resume : pause}>
                        {isPaused ? 'Resume' : 'Pause'}
                    </button>
                    <button onClick={cancel}>Cancel</button>
                </div>
            )}

            {isComplete && <p>Upload complete!</p>}

            {error && (
                <div>
                    <p>{error}</p>
                    <button onClick={retry}>Retry</button>
                </div>
            )}
        </div>
    );
}
```

## With Context

```tsx
import { useChunkUpload } from '@netipar/chunky-react';

function AvatarUpload() {
    const { upload, progress, isUploading, isComplete } = useChunkUpload({
        context: 'profile_avatar',
    });

    const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) upload(file);
    };

    return (
        <div>
            <input type="file" accept="image/*" onChange={handleChange} disabled={isUploading} />
            {isUploading && <progress value={progress} max={100} />}
            {isComplete && <p>Avatar uploaded!</p>}
        </div>
    );
}
```

## With Metadata

```tsx
const { upload } = useChunkUpload();

function handleUpload(file: File) {
    upload(file, {
        folder: 'reports',
        description: 'Q4 Report',
        user_id: currentUser.id,
    });
}
```

## Integration with React Router

```tsx
import { useChunkUpload } from '@netipar/chunky-react';
import { useNavigate } from 'react-router-dom';

function DocumentUpload() {
    const navigate = useNavigate();
    const { upload, onComplete, isUploading, progress } = useChunkUpload({
        context: 'documents',
    });

    React.useEffect(() => {
        const unsub = onComplete((result) => {
            // Redirect after upload
            navigate(`/documents?upload_id=${result.uploadId}`);
        });

        return unsub;
    }, [onComplete, navigate]);

    const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) upload(file);
    };

    return (
        <div>
            <input type="file" onChange={handleChange} disabled={isUploading} />
            {isUploading && <progress value={progress} max={100} />}
        </div>
    );
}
```

## API Reference

### `useChunkUpload(options?)`

**Options:**

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `chunkSize` | `number` | Server default (1MB) | Override chunk size in bytes |
| `maxConcurrent` | `number` | `3` | Parallel chunk uploads |
| `autoRetry` | `boolean` | `true` | Auto-retry failed chunks |
| `maxRetries` | `number` | `3` | Max retries per chunk |
| `headers` | `Record<string, string>` | `{}` | Custom request headers |
| `withCredentials` | `boolean` | `true` | Send cookies |
| `context` | `string` | `undefined` | Upload context for validation |
| `endpoints` | `object` | API defaults | Override API endpoints |

**Returns:**

| Property | Type | Description |
|----------|------|-------------|
| `progress` | `number` | 0-100 percentage |
| `isUploading` | `boolean` | Upload in progress |
| `isPaused` | `boolean` | Upload paused |
| `isComplete` | `boolean` | Upload finished |
| `error` | `string \| null` | Error message |
| `uploadId` | `string \| null` | Server-assigned upload ID |
| `uploadedChunks` | `number` | Chunks uploaded so far |
| `totalChunks` | `number` | Total chunks |
| `currentFile` | `File \| null` | Current file being uploaded |
| `upload` | `(file, metadata?) => Promise` | Start upload |
| `pause` | `() => void` | Pause upload |
| `resume` | `() => void` | Resume upload |
| `cancel` | `() => void` | Cancel upload |
| `retry` | `() => void` | Retry failed upload |
| `onProgress` | `(cb) => Unsubscribe` | Progress event |
| `onChunkUploaded` | `(cb) => Unsubscribe` | Chunk complete event |
| `onComplete` | `(cb) => Unsubscribe` | Upload complete event |
| `onError` | `(cb) => Unsubscribe` | Error event |
