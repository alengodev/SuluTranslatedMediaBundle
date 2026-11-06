// @flow
import {fieldRegistry} from 'sulu-admin-bundle/containers';
import MediaCollectionPath from './fields/MediaCollectionPath';

// Read-only collection path in the media details form (see Resources/config/forms/media_details.xml)
fieldRegistry.add('media_collection_path', MediaCollectionPath);
