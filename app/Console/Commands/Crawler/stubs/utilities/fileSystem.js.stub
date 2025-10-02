import fs from 'fs';
import path from 'path';

/**
 * Recursively empty a directory
 */
export function emptyDirectory(directory) {
    if (!fs.existsSync(directory)) {
        return;
    }
    
    const files = fs.readdirSync(directory);
    for (const file of files) {
        const filePath = path.join(directory, file);
        
        if (fs.statSync(filePath).isDirectory()) {
            emptyDirectory(filePath);
            fs.rmdirSync(filePath);
        } else {
            fs.unlinkSync(filePath);
        }
    }
}

/**
 * Save data to JSON file
 */
export function saveData(pageDir, formattedId, data) {
    try {
        fs.writeFileSync(
            path.join(pageDir, `data_${formattedId}.json`),
            JSON.stringify(data, null, 2)
        );
    } catch (error) {
        console.error(`Failed to save data: ${error.message}`);
    }
}
