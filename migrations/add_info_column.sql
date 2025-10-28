-- Migration to add the 'info' column to existing queue_tasks table
-- Run this SQL command if you have an existing queue_tasks table

ALTER TABLE queue_tasks 
ADD COLUMN info TEXT NULL COMMENT 'Success info from task handlers' 
AFTER error;